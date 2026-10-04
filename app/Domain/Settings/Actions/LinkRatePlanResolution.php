<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\RequiredResolutions;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Links the passed rate-plan resolution that adopted a draft or pending plan. Frozen on approval.
 */
final class LinkRatePlanResolution
{
    public function __construct(
        private readonly RequiredResolutions $resolutions,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, RatePlan $plan, Resolution $resolution): RatePlan
    {
        return $this->causer->withCauser($actor, fn (): RatePlan => DB::transaction(function () use ($actor, $plan, $resolution): RatePlan {
            $locked = RatePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('linkResolution', $locked);

            $this->resolutions->assertUsable(ResolutionSubject::RatePlan, $resolution);

            if (RatePlan::query()->where('resolution_id', $resolution->id)->whereKeyNot($locked->id)->exists()) {
                throw DomainRuleViolation::because('governance.errors.resolution_used', ['number' => $resolution->resolution_no]);
            }

            $locked->update(['resolution_id' => $resolution->id]);

            return $locked;
        }, attempts: 3));
    }
}
