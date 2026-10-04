<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementLine;
use App\Enums\Role;
use App\Models\User;

final class StatementLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, StatementLine $line): bool
    {
        return $user->isStaff();
    }

    public function match(User $user, StatementLine $line): bool
    {
        return $line->status === StatementLineStatus::Unmatched && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function ignore(User $user, StatementLine $line): bool
    {
        return $this->match($user, $line);
    }

    /**
     * Undo a match or an "ignore", returning the line to the unmatched list.
     */
    public function unmatch(User $user, StatementLine $line): bool
    {
        return $line->status !== StatementLineStatus::Unmatched && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StatementLine $line): bool
    {
        return false;
    }

    public function delete(User $user, StatementLine $line): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StatementLine $line): bool
    {
        return false;
    }
}
