<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\Period;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Re-opens a locked month. Months of a closed fiscal year can never be re-opened.
 */
final class UnlockPeriod
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Period $period): Period
    {
        Gate::forUser($actor)->authorize('unlock', $period);

        return $this->causer->withCauser($actor, fn (): Period => DB::transaction(function () use ($period): Period {
            $fiscalYear = FiscalYear::query()->whereKey($period->fiscal_year_id)->lockForUpdate()->firstOrFail();
            $locked = Period::query()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if (! $fiscalYear->isOpen()) {
                throw DomainRuleViolation::because('accounting.errors.fiscal_year_closed', ['code' => $fiscalYear->code]);
            }

            $locked->forceFill([
                'status' => PeriodStatus::Open,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
