<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Notifications\Models\SmsTemplate;
use App\Enums\Role;
use App\Models\User;

final class SmsTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, SmsTemplate $template): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, SmsTemplate $template): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Secretary);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, SmsTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
