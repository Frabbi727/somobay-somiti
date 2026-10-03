<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * For ledger and child rows (journal lines, allocations, share lots, …): staff may read them, but
 * they only ever change through the Actions of their parent record, never directly.
 */
trait ReadOnlyForStaff
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Model $model): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }
}
