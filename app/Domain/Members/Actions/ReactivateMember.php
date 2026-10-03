<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class ReactivateMember
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Member $member): Member
    {
        return $this->causer->withCauser($actor, fn (): Member => DB::transaction(function () use ($actor, $member): Member {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reactivate', $locked);

            $locked->forceFill([
                'status' => MemberStatus::Active,
                'deactivated_at' => null,
                'deactivation_reason' => null,
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
