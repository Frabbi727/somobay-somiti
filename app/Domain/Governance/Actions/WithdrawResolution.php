<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class WithdrawResolution
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Resolution $resolution): Resolution
    {
        return $this->causer->withCauser($actor, fn (): Resolution => DB::transaction(function () use ($actor, $resolution): Resolution {
            $locked = Resolution::query()->whereKey($resolution->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('withdraw', $locked);

            $locked->update(['status' => ResolutionStatus::Withdrawn]);

            return $locked;
        }, attempts: 3));
    }
}
