<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;

/**
 * Maker-checker for spending: the cashier or accountant records; a different accountant or the
 * president approves, and above the threshold only the president.
 */
final class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Collections->allows($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return Area::Collections->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Cashier, Role::Accountant, Role::President);
    }

    public function approve(User $user, Expense $expense): bool
    {
        if ($expense->status !== ExpenseStatus::Pending || $expense->recorded_by === $user->id) {
            return false;
        }

        return $expense->needsPresident()
            ? $user->hasAnyOf(Role::President)
            : $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function reject(User $user, Expense $expense): bool
    {
        return $expense->status === ExpenseStatus::Pending
            && $expense->recorded_by !== $user->id
            && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function cancel(User $user, Expense $expense): bool
    {
        return $expense->status === ExpenseStatus::Pending && $expense->recorded_by === $user->id;
    }

    public function reverse(User $user, Expense $expense): bool
    {
        return $expense->status === ExpenseStatus::Approved && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, Expense $expense): bool
    {
        return false;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
