<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\Period;
use App\Enums\Role;
use App\Models\User;

final class PeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Period $period): bool
    {
        return $user->isStaff();
    }

    public function lock(User $user, Period $period): bool
    {
        return $period->isOpen() && $user->hasAnyOf(Role::President, Role::Accountant);
    }

    public function unlock(User $user, Period $period): bool
    {
        return ! $period->isOpen()
            && $period->fiscalYear->isOpen()
            && $user->hasAnyOf(Role::President, Role::Accountant);
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
