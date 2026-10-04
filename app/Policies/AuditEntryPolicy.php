<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Audit\Models\AuditEntry;
use App\Enums\Permission;
use App\Models\User;

/**
 * The audit log can be read by whoever holds "view audit log"; nobody can change it.
 */
final class AuditEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->may(Permission::ViewAuditLog);
    }

    public function view(User $user, AuditEntry $entry): bool
    {
        return $user->may(Permission::ViewAuditLog);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, AuditEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, AuditEntry $entry): bool
    {
        return false;
    }
}
