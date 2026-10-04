<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Which staff role holds which Permission (stored in spatie/laravel-permission's tables).
 */
final class RolePermissions
{
    public const string GUARD = 'web';

    /**
     * Creates the roles and any permission that does not exist yet, granting a new permission to its
     * default roles. Permissions that already exist are left as the president set them.
     */
    public function installDefaults(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $roles = [];
        foreach (Role::cases() as $role) {
            $roles[$role->value] = RoleModel::findOrCreate($role->value, self::GUARD);
        }

        $existing = PermissionModel::query()->where('guard_name', self::GUARD)->pluck('name')->all();

        foreach (Permission::cases() as $permission) {
            if (in_array($permission->value, $existing, true)) {
                continue;
            }

            $model = PermissionModel::query()->create(['name' => $permission->value, 'guard_name' => self::GUARD]);

            foreach ($permission->defaultRoles() as $role) {
                if (! $permission->isLockedFor($role)) {
                    $roles[$role->value]->givePermissionTo($model);
                }
            }
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * The current grid: permission value => role value => granted.
     *
     * @return array<string, array<string, bool>>
     */
    public function grid(): array
    {
        $granted = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.guard_name', self::GUARD)
            ->get(['roles.name as role', 'permissions.name as permission'])
            ->map(fn (object $row): string => $row->permission.'|'.$row->role)
            ->all();

        $grid = [];
        foreach (Permission::cases() as $permission) {
            foreach (Permission::editableRoles() as $role) {
                $grid[$permission->value][$role->value] = in_array($permission->value.'|'.$role->value, $granted, true);
            }
        }

        return $grid;
    }
}
