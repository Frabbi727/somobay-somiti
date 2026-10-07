<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Models\NomineeRelation;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class NomineeRelationPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Members->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::MembersUpdate);
    }

    public function update(User $user, NomineeRelation $relation): bool
    {
        return $user->may(Permission::MembersUpdate);
    }

    public function delete(User $user, NomineeRelation $relation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, NomineeRelation $relation): bool
    {
        return false;
    }
}
