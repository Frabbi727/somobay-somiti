<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

/**
 * Staff see registrations like members; only the role at the current step may decide, and never
 * the applicant's own account. Applicants act through their token, not through this policy.
 */
final class MemberApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Members->allows($user);
    }

    public function view(User $user, MemberApplication $application): bool
    {
        return Area::Members->allows($user);
    }

    /** Invite: create the login for a new member. */
    public function create(User $user): bool
    {
        return $user->may(Permission::MembersCreate);
    }

    public function decide(User $user, MemberApplication $application): bool
    {
        $role = $application->currentRole();

        return $application->status === MemberApplicationStatus::Submitted
            && $role !== null
            && $user->isStaff()
            && $user->hasAnyOf($role)
            && $application->user_id !== $user->id
            && ! $application->hasDecidedThisSubmission($user);
    }

    public function update(User $user, MemberApplication $application): bool
    {
        return false;
    }

    public function delete(User $user, MemberApplication $application): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, MemberApplication $application): bool
    {
        return false;
    }
}
