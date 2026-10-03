<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Members\Models\Member;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Each member signs in to the portal as a user with the "member" role, linked by members.user_id.
 * That role can never enter the staff panel.
 */
final class PortalAccounts
{
    public function forMember(Member $member): User
    {
        if ($member->user_id !== null) {
            $user = User::query()->find($member->user_id);

            if ($user !== null) {
                return $user;
            }
        }

        $user = User::query()->create([
            'name' => $member->name_en,
            'email' => null,
            'password' => Str::random(48),
            'locale' => 'bn',
        ]);
        $user->assignRole(Role::Member->value);

        $member->forceFill(['user_id' => $user->id])->saveQuietly();

        return $user;
    }

    public function memberOf(User $user): ?Member
    {
        return Member::query()->where('user_id', $user->id)->first();
    }
}
