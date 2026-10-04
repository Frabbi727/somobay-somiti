<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Notifications\Models\SmsTemplate;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class SmsTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Messaging->allows($user);
    }

    public function view(User $user, SmsTemplate $template): bool
    {
        return Area::Messaging->allows($user);
    }

    public function update(User $user, SmsTemplate $template): bool
    {
        return $user->may(Permission::SmsTemplatesEdit);
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
