<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

/**
 * The accountant or secretary records an investment; the president approves it (§1.3) and is the
 * one who may write it down or close it.
 */
final class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Accounting->allows($user);
    }

    public function view(User $user, Investment $investment): bool
    {
        return Area::Accounting->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::InvestmentsRecord);
    }

    public function approve(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Pending
            && $investment->recorded_by !== $user->id
            && $user->may(Permission::InvestmentsApprove);
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
            && $user->may(Permission::InvestmentsIncome);
    }

    public function impair(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Active && $user->may(Permission::InvestmentsWriteDown);
    }

    public function close(User $user, Investment $investment): bool
    {
        return $investment->status === InvestmentStatus::Active && $user->may(Permission::InvestmentsClose);
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
