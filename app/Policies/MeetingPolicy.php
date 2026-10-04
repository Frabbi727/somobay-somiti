<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Governance\Models\Meeting;
use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;

/**
 * The secretary runs meetings (§1.3); the president may too. Held or cancelled meetings are records.
 */
final class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Governance->allows($user);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return Area::Governance->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->status->isOpen() && $this->create($user);
    }

    public function hold(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function cancel(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Meeting $meeting): bool
    {
        return false;
    }
}
