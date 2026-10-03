<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RatePlanRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Sends a draft to the president and secretary for approval. Each submission is numbered,
 * so decisions on an earlier submission never count towards a later one.
 */
final class SubmitRatePlan
{
    public function __construct(
        private readonly RatePlanRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, RatePlan $plan): RatePlan
    {
        return $this->causer->withCauser($actor, fn (): RatePlan => DB::transaction(function () use ($actor, $plan): RatePlan {
            $locked = RatePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('submit', $locked);

            if ($locked->status !== RatePlanStatus::Draft) {
                throw DomainRuleViolation::because('rates.errors.not_editable', ['code' => $locked->code]);
            }

            $this->rules->assertMonthOpen($locked);

            $locked->forceFill([
                'status' => RatePlanStatus::PendingApproval,
                'submission_no' => $locked->submission_no + 1,
                'submitted_at' => CarbonImmutable::now(),
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
