<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;

/**
 * Dues are created by generators and settled by payments; staff only view them and waive late fees.
 */
final class DuePolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Collections->allows($user);
    }

    public function view(User $user, Due $due): bool
    {
        return Area::Collections->allows($user);
    }

    public function waive(User $user, Due $due): bool
    {
        return $due->type === DueType::LateFee
            && $due->status === DueStatus::Open
            && $due->paid_poisha->isZero()
            && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Due $due): bool
    {
        return false;
    }

    public function delete(User $user, Due $due): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
