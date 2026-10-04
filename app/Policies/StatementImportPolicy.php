<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\StatementImport;
use App\Enums\Role;
use App\Models\User;

/**
 * Reconciliation is the accountant's job (§1.3); the president may help. Imports are evidence
 * and are never edited or deleted.
 */
final class StatementImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, StatementImport $import): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, StatementImport $import): bool
    {
        return false;
    }

    public function delete(User $user, StatementImport $import): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, StatementImport $import): bool
    {
        return false;
    }
}
