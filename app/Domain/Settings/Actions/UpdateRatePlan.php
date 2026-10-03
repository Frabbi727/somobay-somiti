<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RatePlanRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Edits a draft. The effective month cannot move, because it is part of the version code.
 */
final class UpdateRatePlan
{
    public function __construct(
        private readonly RatePlanRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, RatePlan $plan, RatePlanData $data): RatePlan
    {
        $this->rules->assertValid($data);

        return $this->causer->withCauser($actor, fn (): RatePlan => DB::transaction(function () use ($actor, $plan, $data): RatePlan {
            $locked = RatePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);

            if (! $locked->status->isEditable()) {
                throw DomainRuleViolation::because('rates.errors.not_editable', ['code' => $locked->code]);
            }

            if (! $locked->effective_from->equals($data->effectiveFrom)) {
                throw DomainRuleViolation::because('rates.errors.month_fixed', ['code' => $locked->code]);
            }

            $locked->fill($data->toAttributes())->save();

            return $locked;
        }, attempts: 3));
    }
}
