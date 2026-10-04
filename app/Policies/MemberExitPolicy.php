<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;

/**
 * The secretary records an exit request; the president approves the settlement (§1.3), never the
 * person who requested it; the accountant or president pays it out.
 */
final class MemberExitPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Governance->allows($user);
    }

    public function view(User $user, MemberExit $exit): bool
    {
        return Area::Governance->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function approve(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Requested
            && $exit->requested_by !== $user->id
            && $user->hasAnyOf(Role::President);
    }

    public function cancel(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Requested && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function pay(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Approved && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, MemberExit $exit): bool
    {
        return false;
    }

    public function delete(User $user, MemberExit $exit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, MemberExit $exit): bool
    {
        return false;
    }
}
