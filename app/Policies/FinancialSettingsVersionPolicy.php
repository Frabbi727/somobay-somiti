<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Enums\Permission;
use App\Models\User;

/**
 * T-19: one person proposes new financial settings, someone else approves or rejects them; the
 * proposer may withdraw a proposal while it waits. Versions are never edited or deleted.
 */
final class FinancialSettingsVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->may(Permission::FinancialSettingsPropose) || $user->may(Permission::FinancialSettingsApprove);
    }

    public function view(User $user, FinancialSettingsVersion $version): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::FinancialSettingsPropose);
    }

    public function approve(User $user, FinancialSettingsVersion $version): bool
    {
        return $version->status === FinancialSettingsStatus::Pending
            && $version->proposed_by !== $user->id
            && $user->may(Permission::FinancialSettingsApprove);
    }

    public function reject(User $user, FinancialSettingsVersion $version): bool
    {
        return $this->approve($user, $version);
    }

    public function cancel(User $user, FinancialSettingsVersion $version): bool
    {
        return $version->status === FinancialSettingsStatus::Pending && $version->proposed_by === $user->id;
    }

    public function update(User $user, FinancialSettingsVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, FinancialSettingsVersion $version): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, FinancialSettingsVersion $version): bool
    {
        return false;
    }
}
