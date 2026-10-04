<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\JournalDraft;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class JournalDraftPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Accounting->allows($user);
    }

    public function view(User $user, JournalDraft $draft): bool
    {
        return Area::Accounting->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::JournalsManage);
    }

    public function update(User $user, JournalDraft $draft): bool
    {
        return ! $draft->isPosted() && ! $draft->trashed() && $user->may(Permission::JournalsManage);
    }

    public function post(User $user, JournalDraft $draft): bool
    {
        return ! $draft->isPosted() && ! $draft->trashed() && $user->may(Permission::JournalsManage);
    }

    public function delete(User $user, JournalDraft $draft): bool
    {
        return ! $draft->isPosted() && $user->may(Permission::JournalsManage);
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
