<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Services\RolePermissions;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * The president changes which role holds which permission. The auditor can never be given a
 * permission that changes something, and every permission stays with at least one role so no
 * part of the work can get stuck. Returns the changes made.
 */
final class UpdateRolePermissions
{
    public function __construct(
        private readonly RolePermissions $permissions,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array<string, array<string, bool>>  $grid  permission value => role value => granted
     * @return list<array{permission: Permission, role: Role, granted: bool}>
     */
    public function __invoke(User $actor, array $grid): array
    {
        Gate::forUser($actor)->authorize('manageRolePermissions');

        return $this->causer->withCauser($actor, fn (): array => DB::transaction(function () use ($grid): array {
            $roles = RoleModel::query()
                ->where('guard_name', RolePermissions::GUARD)
                ->whereIn('name', array_map(fn (Role $role): string => $role->value, Permission::editableRoles()))
                ->with('permissions')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('name');

            $current = $this->permissions->grid();
            $changes = [];

            foreach (Permission::cases() as $permission) {
                $holders = 0;

                foreach (Permission::editableRoles() as $role) {
                    $wanted = (bool) ($grid[$permission->value][$role->value] ?? false);

                    if ($wanted && $permission->isLockedFor($role)) {
                        throw DomainRuleViolation::because('permissions.errors.auditor_read_only', ['permission' => $permission->getLabel()]);
                    }

                    $holders += $wanted ? 1 : 0;

                    if ($wanted !== $current[$permission->value][$role->value]) {
                        $changes[] = ['permission' => $permission, 'role' => $role, 'granted' => $wanted];
                    }
                }

                if ($holders === 0) {
                    throw DomainRuleViolation::because('permissions.errors.nobody_left', ['permission' => $permission->getLabel()]);
                }
            }

            if ($changes === []) {
                throw DomainRuleViolation::because('permissions.errors.nothing_changed');
            }

            $models = PermissionModel::query()->where('guard_name', RolePermissions::GUARD)->get()->keyBy('name');

            foreach ($changes as $change) {
                $role = $roles->get($change['role']->value);
                $model = $models->get($change['permission']->value);

                if ($role === null || $model === null) {
                    throw new \LogicException('Roles and permissions must be installed first.');
                }

                $change['granted'] ? $role->givePermissionTo($model) : $role->revokePermissionTo($model);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            activity('permissions')
                ->event('updated')
                ->withProperties(['changes' => array_map(fn (array $change): array => [
                    'permission' => $change['permission']->value,
                    'role' => $change['role']->value,
                    'granted' => $change['granted'],
                ], $changes)])
                ->log('role permissions changed');

            return $changes;
        }, attempts: 3));
    }
}
