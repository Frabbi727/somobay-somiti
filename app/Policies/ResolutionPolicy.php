<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Enums\Role;
use App\Models\User;

final class ResolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Resolution $resolution): bool
    {
        return $user->isStaff();
    }

    /**
     * Proposals may be added before the meeting or during it (until it is closed as held).
     */
    public function create(User $user, ?Meeting $meeting = null): bool
    {
        return $user->hasAnyOf(Role::Secretary, Role::President)
            && ($meeting === null || $meeting->status !== MeetingStatus::Cancelled);
    }

    public function decide(User $user, Resolution $resolution): bool
    {
        return $resolution->status === ResolutionStatus::Proposed && $user->hasAnyOf(Role::Secretary, Role::President);
    }

    public function withdraw(User $user, Resolution $resolution): bool
    {
        return $this->decide($user, $resolution);
    }

    public function update(User $user, Resolution $resolution): bool
    {
        return false;
    }

    public function delete(User $user, Resolution $resolution): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Resolution $resolution): bool
    {
        return false;
    }
}
