<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\Account;
use App\Enums\Role;
use App\Models\User;

final class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Account $account): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Accountant);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Accountant);
    }

    /**
     * Only an account that was never posted to may be (soft) deleted.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Accountant) && ! $account->hasJournalLines();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Account $account): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Accountant);
    }

    public function forceDelete(User $user, Account $account): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
