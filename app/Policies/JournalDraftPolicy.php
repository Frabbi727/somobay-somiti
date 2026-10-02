<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\JournalDraft;
use App\Enums\Role;
use App\Models\User;

final class JournalDraftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, JournalDraft $draft): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function update(User $user, JournalDraft $draft): bool
    {
        return ! $draft->isPosted() && ! $draft->trashed() && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function post(User $user, JournalDraft $draft): bool
    {
        return ! $draft->isPosted() && ! $draft->trashed() && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function delete(User $user, JournalDraft $draft): bool
    {
        return ! $draft->isPosted() && $user->hasAnyOf(Role::Accountant, Role::President);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, JournalDraft $draft): bool
    {
        return false;
    }

    public function forceDelete(User $user, JournalDraft $draft): bool
    {
        return false;
    }
}
