<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Enums\Role;
use App\Models\User;

/**
 * Maker-checker (BR-12, G4): the cashier (or accountant) records; a different accountant or the
 * president approves. The super admin never approves financial entries.
 */
final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Cashier, Role::Accountant, Role::President);
    }

    public function approve(User $user, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $payment->recorded_by !== $user->id
            && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function reject(User $user, Payment $payment): bool
    {
        return $this->approve($user, $payment);
    }

    public function cancel(User $user, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending && $payment->recorded_by === $user->id;
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Approved && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
