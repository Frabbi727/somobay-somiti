<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\Period;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Locks a month so nothing more can be posted into it. Locking an already locked period is a no-op.
 */
final class LockPeriod
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Period $period): Period
    {
        Gate::forUser($actor)->authorize('lock', $period);

        return $this->causer->withCauser($actor, fn (): Period => DB::transaction(function () use ($actor, $period): Period {
            $locked = Period::query()->whereKey($period->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isOpen()) {
                $locked->forceFill([
                    'status' => PeriodStatus::Locked,
                    'locked_at' => CarbonImmutable::now(),
                    'locked_by' => $actor->getKey(),
                ])->save();
            }

            return $locked;
        }, attempts: 3));
    }
}
