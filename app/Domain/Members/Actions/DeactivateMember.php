<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Pauses a member: no new monthly dues are generated until reactivated. History is untouched.
 */
final class DeactivateMember
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Member $member, string $reason): Member
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw DomainRuleViolation::because('members.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): Member => DB::transaction(function () use ($actor, $member, $reason): Member {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('deactivate', $locked);

            $locked->forceFill([
                'status' => MemberStatus::Inactive,
                'deactivated_at' => CarbonImmutable::now(),
                'deactivation_reason' => trim($reason),
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
