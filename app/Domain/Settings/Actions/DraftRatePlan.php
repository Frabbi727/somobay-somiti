<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RatePlanRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Starts a new rate plan version as a draft, coded RP-{YYYY-MM}-v{n}.
 */
final class DraftRatePlan
{
    public function __construct(
        private readonly RatePlanRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, RatePlanData $data): RatePlan
    {
        Gate::forUser($actor)->authorize('create', RatePlan::class);
        $this->rules->assertValid($data);

        return $this->causer->withCauser($actor, fn (): RatePlan => DB::transaction(function () use ($actor, $data): RatePlan {
            DB::statement('LOCK TABLE rate_plans IN SHARE ROW EXCLUSIVE MODE');

            $version = RatePlan::withTrashed()->where('effective_from', $data->effectiveFrom->toDateString())->count() + 1;

            return RatePlan::query()->create([
                ...$data->toAttributes(),
                'code' => sprintf('RP-%s-v%d', $data->effectiveFrom, $version),
                'status' => RatePlanStatus::Draft,
                'created_by' => $actor->id,
            ]);
        }, attempts: 3));
    }
}
