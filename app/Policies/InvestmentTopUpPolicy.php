<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

/**
 * Same people as the investment itself: the accountant or secretary records more money for it,
 * and someone else with the approval permission (the president) approves it.
 */
final class InvestmentTopUpPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Accounting->allows($user);
    }

    public function view(User $user, InvestmentTopUp $topUp): bool
    {
        return Area::Accounting->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::InvestmentsRecord);
    }

    public function approve(User $user, InvestmentTopUp $topUp): bool
    {
        return $topUp->status === InvestmentTopUpStatus::Pending
            && $topUp->recorded_by !== $user->id
            && $user->may(Permission::InvestmentsApprove);
    }

    public function reject(User $user, InvestmentTopUp $topUp): bool
    {
        return $this->approve($user, $topUp);
    }

    public function cancel(User $user, InvestmentTopUp $topUp): bool
    {
        return $topUp->status === InvestmentTopUpStatus::Pending && $topUp->recorded_by === $user->id;
    }

    public function update(User $user, InvestmentTopUp $topUp): bool
    {
        return false;
    }

    public function delete(User $user, InvestmentTopUp $topUp): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, InvestmentTopUp $topUp): bool
    {
        return false;
    }
}
