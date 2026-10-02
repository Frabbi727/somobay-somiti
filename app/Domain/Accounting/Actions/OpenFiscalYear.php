<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates a fiscal year (1 July – 30 June) with its twelve open monthly periods.
 * Years must follow each other without gaps.
 */
final class OpenFiscalYear
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, int $startYear): FiscalYear
    {
        Gate::forUser($actor)->authorize('create', FiscalYear::class);

        return $this->causer->withCauser($actor, fn (): FiscalYear => DB::transaction(function () use ($startYear): FiscalYear {
            DB::statement('LOCK TABLE fiscal_years IN SHARE ROW EXCLUSIVE MODE');

            if (FiscalYear::query()->where('start_year', $startYear)->exists()) {
                throw DomainRuleViolation::because('accounting.errors.fiscal_year_exists', ['code' => FiscalCalendar::code($startYear)]);
            }

            $earliest = FiscalYear::query()->min('start_year');
            $latest = FiscalYear::query()->max('start_year');

            if ($latest !== null && $startYear !== (int) $latest + 1 && $startYear !== (int) $earliest - 1) {
                throw DomainRuleViolation::because('accounting.errors.fiscal_year_gap', [
                    'next' => FiscalCalendar::code((int) $latest + 1),
                    'previous' => FiscalCalendar::code((int) $earliest - 1),
                ]);
            }

            $fiscalYear = FiscalYear::query()->create([
                'start_year' => $startYear,
                'code' => FiscalCalendar::code($startYear),
                'starts_on' => FiscalCalendar::startsOn($startYear)->toDateString(),
                'ends_on' => FiscalCalendar::endsOn($startYear)->toDateString(),
                'status' => FiscalYearStatus::Open,
            ]);

            foreach (FiscalCalendar::months($startYear) as $index => $month) {
                $fiscalYear->periods()->create([
                    'sequence' => $index + 1,
                    'month' => $month,
                    'status' => PeriodStatus::Open,
                ]);
            }

            return $fiscalYear;
        }, attempts: 3));
    }
}
