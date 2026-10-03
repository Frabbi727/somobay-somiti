<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Contracts\RatePlanUsage;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Withdraws a plan with a reason. An approved plan can be cancelled only while no due uses it (BR-6).
 */
final class CancelRatePlan
{
    public function __construct(
        private readonly RatePlanUsage $usage,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, RatePlan $plan, string $reason): RatePlan
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw DomainRuleViolation::because('rates.errors.comment_required');
        }

        return $this->causer->withCauser($actor, fn (): RatePlan => DB::transaction(function () use ($actor, $plan, $reason): RatePlan {
            $locked = RatePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('cancel', $locked);

            if (in_array($locked->status, [RatePlanStatus::Cancelled, RatePlanStatus::Superseded], true)) {
                throw DomainRuleViolation::because('rates.errors.already_closed', ['code' => $locked->code]);
            }

            if ($locked->status === RatePlanStatus::Approved && $this->usage->isReferenced($locked)) {
                throw DomainRuleViolation::because('rates.errors.month_in_use', ['code' => $locked->code]);
            }

            $locked->forceFill([
                'status' => RatePlanStatus::Cancelled,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_reason' => trim($reason),
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
