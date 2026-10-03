<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Staff accounts are managed by the super admin (SOMITI_SPEC.md §2 roles). Accounts are
 * deactivated, never deleted, so the audit trail keeps its authors.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin) && ! $model->hasAnyOf(Role::Member);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
