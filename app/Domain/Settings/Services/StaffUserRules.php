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

    /**
     * Roles that record, approve or reverse money; none of them may sit with super admin.
     *
     * @var list<Role>
     */
    public const array MONEY_ROLES = [Role::President, Role::Accountant, Role::Cashier];

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

        // The super-admin lock on approving and reversing is per role, so the same person must not
        // also hold a role that records or approves money (known gap #18).
        $holdsMoneyRole = array_any(self::MONEY_ROLES, fn (Role $role): bool => in_array($role, $data->roles, true));

        if ($holdsMoneyRole && in_array(Role::SuperAdmin, $data->roles, true)) {
            throw DomainRuleViolation::because('users.errors.super_admin_combined');
        }

        if ($existing === null && $data->password === null) {
            throw DomainRuleViolation::because('users.errors.password_required');
        }

        if ($data->password !== null && mb_strlen($data->password) < self::MIN_PASSWORD_LENGTH) {
            throw DomainRuleViolation::because('users.errors.password_short', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
    }

    /**
     * Nobody changes their own roles: otherwise the super admin could make themselves president
     * and step around every two-person rule. Another super admin has to do it.
     */
    public function assertNotChangingOwnRoles(User $actor, User $target, StaffUserData $data): void
    {
        if (! $actor->is($target)) {
            return;
        }

        $current = $target->getRoleNames()->sort()->values()->all();
        $wanted = collect($data->roleNames())->sort()->values()->all();

        if ($current !== $wanted) {
            throw DomainRuleViolation::because('users.errors.own_roles');
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
