<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class SmsMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Messaging->allows($user);
    }

    public function view(User $user, SmsMessage $message): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Try a failed message again; never a login code (it has expired by then).
     */
    public function resend(User $user, SmsMessage $message): bool
    {
        return $message->status === SmsStatus::Failed
            && $message->template_key !== SmsTemplateKey::LoginCode->value
            && $user->may(Permission::SmsTemplatesEdit);
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
