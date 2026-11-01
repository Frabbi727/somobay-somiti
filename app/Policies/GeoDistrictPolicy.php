<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Geo\Models\GeoDistrict;
use App\Models\User;

/**
 * Public reference data: everyone signed in may read it, nobody edits it through the app.
 */
final class GeoDistrictPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, GeoDistrict $place): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GeoDistrict $place): bool
    {
        return false;
    }

    public function delete(User $user, GeoDistrict $place): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, GeoDistrict $place): bool
    {
        return false;
    }
}
