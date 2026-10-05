<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Permission;
use App\Models\User;

/**
 * Everyone on the staff sees the society's details; "edit the society profile" changes them.
 */
final class SomitiProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, SomitiProfile $profile): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, ?SomitiProfile $profile = null): bool
    {
        return $user->may(Permission::SomitiProfileEdit);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, SomitiProfile $profile): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
