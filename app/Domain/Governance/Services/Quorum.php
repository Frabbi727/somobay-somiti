<?php

declare(strict_types=1);

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Enums\MeetingType;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Enums\Role;
use App\Models\User;

/**
 * Who may attend a meeting, and how many must be present (somiti.quorum_bps, from the bylaws).
 */
final class Quorum
{
    /**
     * Roles that make up the managing committee.
     *
     * @var list<Role>
     */
    public const array COMMITTEE_ROLES = [Role::President, Role::Secretary, Role::Cashier, Role::Accountant];

    public function eligible(MeetingType $type): int
    {
        if ($type->isOfMembers()) {
            return Member::query()->where('status', MemberStatus::Active)->count();
        }

        return User::query()
            ->role(array_map(fn (Role $role): string => $role->value, self::COMMITTEE_ROLES))
            ->whereNull('deactivated_at')
            ->count();
    }

    /**
     * eligible × bps / 10,000, rounded up to a whole person, and at least one.
     */
    public function required(MeetingType $type, int $eligible): int
    {
        $bps = (int) config('somiti.quorum_bps.'.$type->value, 5001);

        return max(1, intdiv($eligible * $bps + 9_999, 10_000));
    }
}
