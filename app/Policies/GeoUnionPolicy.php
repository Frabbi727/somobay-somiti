<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Geo\Models\GeoUnion;
use App\Models\User;

/**
 * Public reference data: everyone signed in may read it, nobody edits it through the app.
 */
final class GeoUnionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, GeoUnion $place): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GeoUnion $place): bool
    {
        return false;
    }

    public function delete(User $user, GeoUnion $place): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, GeoUnion $place): bool
    {
        return false;
    }
}
