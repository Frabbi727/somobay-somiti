<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Soft-deletes a draft that was never submitted (§7.4). Anything once submitted is cancelled instead.
 */
final class DeleteRatePlan
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, RatePlan $plan): void
    {
        $this->causer->withCauser($actor, fn () => DB::transaction(function () use ($actor, $plan): void {
            $locked = RatePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $locked);

            if (! $locked->status->isEditable() || $locked->submission_no > 0) {
                throw DomainRuleViolation::because('rates.errors.not_deletable', ['code' => $locked->code]);
            }

            $locked->delete();
        }, attempts: 3));
    }
}
