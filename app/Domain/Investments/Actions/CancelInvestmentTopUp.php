<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The recorder withdraws their own pending top-up.
 */
final class CancelInvestmentTopUp
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, InvestmentTopUp $topUp): InvestmentTopUp
    {
        return $this->causer->withCauser($actor, fn (): InvestmentTopUp => DB::transaction(function () use ($actor, $topUp): InvestmentTopUp {
            $locked = InvestmentTopUp::query()->with('investment')->whereKey($topUp->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentTopUpStatus::Pending) {
                throw DomainRuleViolation::because('investments.errors.top_up_not_pending', ['number' => $locked->investment->investment_no]);
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->update(['status' => InvestmentTopUpStatus::Cancelled]);

            return $locked;
        }, attempts: 3));
    }
}
