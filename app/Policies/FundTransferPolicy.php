<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Enums\Role;
use App\Models\User;

/**
 * The cashier or accountant records a transfer (e.g. a bank deposit); a different accountant or
 * the president approves it.
 */
final class FundTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, FundTransfer $transfer): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Cashier, Role::Accountant, Role::President);
    }

    public function approve(User $user, FundTransfer $transfer): bool
    {
        return $transfer->status === TransferStatus::Pending
            && $transfer->recorded_by !== $user->id
            && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function reject(User $user, FundTransfer $transfer): bool
    {
        return $this->approve($user, $transfer);
    }

    public function cancel(User $user, FundTransfer $transfer): bool
    {
        return $transfer->status === TransferStatus::Pending && $transfer->recorded_by === $user->id;
    }

    public function reverse(User $user, FundTransfer $transfer): bool
    {
        return $transfer->status === TransferStatus::Approved && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, FundTransfer $transfer): bool
    {
        return false;
    }

    public function delete(User $user, FundTransfer $transfer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, FundTransfer $transfer): bool
    {
        return false;
    }
}
