<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Geo\Models\GeoDivision;
use App\Models\User;

/**
 * Public reference data: everyone signed in may read it, nobody edits it through the app.
 */
final class GeoDivisionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, GeoDivision $place): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GeoDivision $place): bool
    {
        return false;
    }

    public function delete(User $user, GeoDivision $place): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, GeoDivision $place): bool
    {
        return false;
    }
}
