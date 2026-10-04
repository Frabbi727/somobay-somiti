<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\FiscalYear;
use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;

final class FiscalYearPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Accounting->allows($user);
    }

    public function view(User $user, FiscalYear $fiscalYear): bool
    {
        return Area::Accounting->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Accountant);
    }

    /**
     * Fiscal years are never edited; they are only opened and closed.
     */
    public function update(User $user, FiscalYear $fiscalYear): bool
    {
        return false;
    }

    public function close(User $user, FiscalYear $fiscalYear): bool
    {
        return $fiscalYear->isOpen() && $user->hasAnyOf(Role::President, Role::Accountant);
    }

    public function delete(User $user, FiscalYear $fiscalYear): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, FiscalYear $fiscalYear): bool
    {
        return false;
    }
}
