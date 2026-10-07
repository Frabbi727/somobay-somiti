<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Settings\Services\RolePermissions;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Sets who approves a member's own registration, in order. Registrations already submitted keep
 * the order they were submitted with (spec §6 R9).
 */
final class UpdateRegistrationApprovalChain
{
    /** @var list<Role> committee roles that may approve a registration */
    public const array ALLOWED = [Role::President, Role::Secretary, Role::Cashier, Role::Accountant];

    public function __construct(private readonly CauserResolver $causer) {}

    /**
     * @param  list<Role>  $roles
     */
    public function __invoke(User $actor, array $roles): SomitiProfile
    {
        Gate::forUser($actor)->authorize('update', SomitiProfile::class);

        $values = array_map(fn (Role $role): string => $role->value, $roles);

        $allowed = array_map(fn (Role $role): string => $role->value, self::ALLOWED);

        if ($values === [] || count(array_unique($values)) !== count($values) || array_diff($values, $allowed) !== []) {
            throw DomainRuleViolation::because('registration.errors.chain_invalid');
        }

        $last = array_last($roles) ?? throw DomainRuleViolation::because('registration.errors.chain_invalid');

        if (! $this->roleHolds($last, Permission::MembersCreate)) {
            throw DomainRuleViolation::because('registration.errors.chain_last_cannot_create', ['role' => $last->getLabel()]);
        }

        return $this->causer->withCauser($actor, fn (): SomitiProfile => DB::transaction(function () use ($actor, $values): SomitiProfile {
            $profile = SomitiProfile::query()->whereKey(SomitiProfile::ID)->lockForUpdate()->first()
                ?? throw DomainRuleViolation::because('registration.errors.profile_first');

            $profile->forceFill(['registration_approval_roles' => $values, 'updated_by' => $actor->id])->save();

            return $profile;
        }, attempts: 3));
    }

    private function roleHolds(Role $role, Permission $permission): bool
    {
        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.guard_name', RolePermissions::GUARD)
            ->where('roles.name', $role->value)
            ->where('permissions.name', $permission->value)
            ->exists();
    }
}
