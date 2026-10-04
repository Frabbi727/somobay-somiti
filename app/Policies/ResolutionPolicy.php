<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class ResolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Governance->allows($user);
    }

    public function view(User $user, Resolution $resolution): bool
    {
        return Area::Governance->allows($user);
    }

    /**
     * Proposals may be added before the meeting or during it (until it is closed as held).
     */
    public function create(User $user, ?Meeting $meeting = null): bool
    {
        return $user->may(Permission::ResolutionsManage)
            && ($meeting === null || $meeting->status !== MeetingStatus::Cancelled);
    }

    public function decide(User $user, Resolution $resolution): bool
    {
        return $resolution->status === ResolutionStatus::Proposed && $user->may(Permission::ResolutionsManage);
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
