<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Enums\Role;
use App\Models\User;

final class DividendLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, DividendLine $line): bool
    {
        return $user->isStaff();
    }

    public function settle(User $user, DividendLine $line): bool
    {
        return $line->status === DividendStatus::Unpaid && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DividendLine $line): bool
    {
        return false;
    }

    public function delete(User $user, DividendLine $line): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, DividendLine $line): bool
    {
        return false;
    }
}
