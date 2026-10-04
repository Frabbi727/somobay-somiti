<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Enums\Role;
use App\Models\User;

/**
 * The accountant or secretary records an investment; the president approves it (§1.3) and is the
 * one who may write it down or close it.
 */
final class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Investment $investment): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Accountant, Role::Secretary, Role::President);
    }

    public function approve(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Pending
            && $investment->recorded_by !== $user->id
            && $user->hasAnyOf(Role::President);
    }

    public function reject(User $user, Investment $investment): bool
    {
        return $this->approve($user, $investment);
    }

    public function cancel(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Pending && $investment->recorded_by === $user->id;
    }

    /**
     * Profit received on an active investment (P10.S2).
     */
    public function recordIncome(User $user, Investment $investment): bool
    {
        return in_array($investment->status, [InvestmentStatus::Active, InvestmentStatus::Closed], true)
            && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function impair(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Active && $user->hasAnyOf(Role::President);
    }

    public function close(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Active && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, Investment $investment): bool
    {
        return false;
    }

    public function delete(User $user, Investment $investment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Investment $investment): bool
    {
        return false;
    }
}
