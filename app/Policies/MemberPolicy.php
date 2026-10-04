<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;

/**
 * The secretary (and president) manage members; everyone on the staff can look them up.
 */
final class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Members->allows($user);
    }

    public function view(User $user, Member $member): bool
    {
        return Area::Members->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function update(User $user, Member $member): bool
    {
        return $member->status !== MemberStatus::Exited && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function changeShares(User $user, Member $member): bool
    {
        return $member->status === MemberStatus::Active && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function deactivate(User $user, Member $member): bool
    {
        return $member->status === MemberStatus::Active && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function reactivate(User $user, Member $member): bool
    {
        return $member->status === MemberStatus::Inactive && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    /**
     * Give or reset the member's portal password (societies without SMS codes).
     */
    public function setPortalPassword(User $user, Member $member): bool
    {
        return $member->status !== MemberStatus::Exited && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function delete(User $user, Member $member): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Member $member): bool
    {
        return false;
    }
}
