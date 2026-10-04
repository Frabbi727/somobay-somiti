<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The maker withdraws their own pending investment.
 */
final class CancelInvestment
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Investment $investment): Investment
    {
        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentStatus::Pending) {
                throw DomainRuleViolation::because('investments.errors.not_pending', ['number' => $locked->investment_no]);
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->update(['status' => InvestmentStatus::Cancelled]);

            return $locked;
        }, attempts: 3));
    }
}
