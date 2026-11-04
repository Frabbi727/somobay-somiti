<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\GoLive\Models\OpeningImport;
use App\Enums\Permission;
use App\Models\User;

/**
 * Staff can see the go-live import; "import members and opening balances" uploads and posts it.
 */
final class OpeningImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, OpeningImport $import): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::OpeningImport);
    }

    public function post(User $user, OpeningImport $import): bool
    {
        return $user->may(Permission::OpeningImport);
    }

    public function update(User $user, OpeningImport $import): bool
    {
        return false;
    }

    public function delete(User $user, OpeningImport $import): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
