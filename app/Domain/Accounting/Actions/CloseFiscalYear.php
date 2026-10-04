<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Closes a fiscal year and locks all its periods. Earlier years must be closed first.
 * The year-end appropriation wizard (Phase 11) will run before this step.
 */
final class CloseFiscalYear
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, FiscalYear $fiscalYear): FiscalYear
    {
        Gate::forUser($actor)->authorize('close', $fiscalYear);

        return $this->causer->withCauser($actor, fn (): FiscalYear => DB::transaction(function () use ($actor, $fiscalYear): FiscalYear {
            $locked = FiscalYear::query()->whereKey($fiscalYear->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw DomainRuleViolation::because('accounting.errors.fiscal_year_closed', ['code' => $locked->code]);
            }

            $earlierOpen = FiscalYear::query()
                ->where('start_year', '<', $locked->start_year)
                ->where('status', FiscalYearStatus::Open)
                ->orderBy('start_year')
                ->first();

            if ($earlierOpen !== null) {
                throw DomainRuleViolation::because('accounting.errors.earlier_year_open', ['code' => $earlierOpen->code]);
            }

            // W8: a year with income or expenses is closed through its year-end (appropriation and
            // dividend), never directly.
            $hasResults = DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('e.fiscal_year_id', $locked->id)
                ->whereIn('a.type', [AccountType::Income->value, AccountType::Expense->value])
                ->exists();

            $yearEndPosted = DB::table('year_ends')->where('fiscal_year_id', $locked->id)->where('status', 'posted')->exists();

            if ($hasResults && ! $yearEndPosted) {
                throw DomainRuleViolation::because('accounting.errors.year_end_required', ['code' => $locked->code]);
            }

            $now = CarbonImmutable::now();

            foreach ($locked->periods()->lockForUpdate()->get() as $period) {
                if ($period->isOpen()) {
                    $period->forceFill([
                        'status' => PeriodStatus::Locked,
                        'locked_at' => $now,
                        'locked_by' => $actor->getKey(),
                    ])->save();
                }
            }

            $locked->forceFill([
                'status' => FiscalYearStatus::Closed,
                'closed_at' => $now,
                'closed_by' => $actor->getKey(),
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
