<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The user that scheduled jobs post as. It has no role, so it can never sign in to the panel.
 */
final class SystemUser
{
    public const string EMAIL = 'system@somiti.local';

    public static function get(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'System', 'password' => Str::random(64)],
        );
    }
}
