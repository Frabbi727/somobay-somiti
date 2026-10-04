<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\Period;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class PeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Accounting->allows($user);
    }

    public function view(User $user, Period $period): bool
    {
        return Area::Accounting->allows($user);
    }

    public function lock(User $user, Period $period): bool
    {
        return $period->isOpen() && $user->may(Permission::PeriodsLock);
    }

    public function unlock(User $user, Period $period): bool
    {
        return ! $period->isOpen()
            && $period->fiscalYear->isOpen()
            && $user->may(Permission::PeriodsLock);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Period $period): bool
    {
        return false;
    }

    public function delete(User $user, Period $period): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
