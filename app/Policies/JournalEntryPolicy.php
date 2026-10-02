<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\JournalEntry;
use App\Enums\Role;
use App\Models\User;

/**
 * Posted vouchers are read-only; the only write is a reversal.
 */
final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->isStaff();
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->hasAnyOf(Role::Accountant, Role::President)
            && ! $entry->isReversal()
            && ! $entry->isReversed();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, JournalEntry $entry): bool
    {
        return false;
    }
}
