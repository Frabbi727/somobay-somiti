<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\StaffUserData;
use App\Domain\Settings\Services\StaffUserRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class UpdateStaffUser
{
    public function __construct(
        private readonly StaffUserRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, User $user, StaffUserData $data): User
    {
        return $this->causer->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $user, $data): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);

            $this->rules->assertValid($data, $locked);

            if ($locked->hasAnyOf(Role::SuperAdmin) && ! in_array(Role::SuperAdmin, $data->roles, true)) {
                $this->rules->assertSuperAdminRemains($locked);
            }

            $before = $locked->getRoleNames()->sort()->values()->all();

            $locked->fill([
                'name' => $data->name,
                'email' => $data->email,
                'mobile' => $data->mobile,
                'locale' => $data->locale,
                ...($data->password === null ? [] : ['password' => $data->password]),
            ])->save();

            $locked->syncRoles($data->roleNames());

            activity('users')->performedOn($locked)->withProperties([
                'roles' => ['old' => $before, 'new' => collect($data->roleNames())->sort()->values()->all()],
                'password_changed' => $data->password !== null,
            ])->log('updated');

            return $locked;
        }, attempts: 3));
    }
}
