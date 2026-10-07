<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Members\Portal\AccountTypes;
use App\Domain\Settings\Services\RolePermissions;
use App\Enums\Permission;
use App\Enums\Role;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'mobile', 'password', 'locale'])]
/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $mobile
 * @property string $locale
 * @property CarbonImmutable|null $deactivated_at
 */
#[Hidden(['password', 'remember_token'])]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    use LogsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'bn',
    ];

    /**
     * The staff panel is for active staff; the member portal for members whose membership has not
     * ended. Nobody gets into the other panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'member') {
            return ! $this->isStaff() && app(AccountTypes::class)->of($this) !== null;
        }

        return $this->isStaff() && $this->isActive();
    }

    /**
     * Deactivated staff can no longer sign in; their name stays on everything they did.
     */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Whether the user holds at least one of the given roles.
     */
    public function hasAnyOf(Role ...$roles): bool
    {
        return $this->hasAnyRole(array_map(fn (Role $role): string => $role->value, $roles));
    }

    /**
     * Whether one of the user's roles holds the permission (Settings › Role permissions).
     */
    public function may(Permission $permission): bool
    {
        return $this->checkPermissionTo($permission->value, RolePermissions::GUARD);
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(Role::staff());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Passwords and remember tokens are never written to the audit log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'email', 'mobile', 'locale', 'deactivated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('users');
    }
}
