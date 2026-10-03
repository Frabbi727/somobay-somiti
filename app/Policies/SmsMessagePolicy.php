<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Role;
use App\Models\User;

final class SmsMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Secretary, Role::Accountant, Role::Auditor);
    }

    public function view(User $user, SmsMessage $message): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SmsMessage $message): bool
    {
        return false;
    }

    public function delete(User $user, SmsMessage $message): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
