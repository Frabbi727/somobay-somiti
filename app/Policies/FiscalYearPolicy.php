<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\FiscalYear;
use App\Enums\Area;
use App\Enums\Permission;
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
        return $user->may(Permission::FiscalYearsOpen);
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
        return $fiscalYear->isOpen() && $user->may(Permission::PeriodsLock);
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
