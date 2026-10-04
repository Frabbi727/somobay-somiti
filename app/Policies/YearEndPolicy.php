<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Enums\Role;
use App\Models\User;

/**
 * The accountant prepares; the president and an accountant approve (T3) before it posts (§1.3, W8).
 */
final class YearEndPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, YearEnd $yearEnd): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function approve(User $user, YearEnd $yearEnd): bool
    {
        if ($yearEnd->status !== YearEndStatus::Draft) {
            return false;
        }

        if ($user->hasAnyOf(Role::President) && $yearEnd->president_approved_by === null) {
            return $yearEnd->accountant_approved_by !== $user->id;
        }

        return $user->hasAnyOf(Role::Accountant)
            && $yearEnd->accountant_approved_by === null
            && $yearEnd->president_approved_by !== $user->id;
    }

    public function update(User $user, YearEnd $yearEnd): bool
    {
        return false;
    }

    public function delete(User $user, YearEnd $yearEnd): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, YearEnd $yearEnd): bool
    {
        return false;
    }
}
