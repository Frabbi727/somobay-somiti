<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Data\StaffUserData;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Contact\MobileNumber;

final class StaffUserRules
{
    public const int MIN_PASSWORD_LENGTH = 12;

    public function assertValid(StaffUserData $data, ?User $existing = null): void
    {
        if ($data->name === '') {
            throw DomainRuleViolation::because('users.errors.name_required');
        }

        if (filter_var($data->email, FILTER_VALIDATE_EMAIL) === false) {
            throw DomainRuleViolation::because('users.errors.email_invalid');
        }

        $taken = User::query()
            ->whereRaw('lower(email) = ?', [$data->email])
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing?->id))
            ->exists();

        if ($taken) {
            throw DomainRuleViolation::because('users.errors.email_taken', ['email' => $data->email]);
        }

        if ($data->mobile !== null && MobileNumber::normalize($data->mobile) === null) {
            throw DomainRuleViolation::because('users.errors.mobile_invalid');
        }

        if ($data->roles === [] || in_array(Role::Member, $data->roles, true)) {
            throw DomainRuleViolation::because('users.errors.staff_role_required');
        }

        if ($existing === null && $data->password === null) {
            throw DomainRuleViolation::because('users.errors.password_required');
        }

        if ($data->password !== null && mb_strlen($data->password) < self::MIN_PASSWORD_LENGTH) {
            throw DomainRuleViolation::because('users.errors.password_short', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
    }

    /**
     * Somebody must always be able to manage users: at least one active super admin stays.
     */
    public function assertSuperAdminRemains(User $changing): void
    {
        $others = User::query()
            ->role(Role::SuperAdmin->value)
            ->whereNull('deactivated_at')
            ->whereKeyNot($changing->id)
            ->exists();

        if (! $others) {
            throw DomainRuleViolation::because('users.errors.last_super_admin');
        }
    }
}
