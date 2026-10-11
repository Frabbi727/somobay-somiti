<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Settings\Data\StaffUserData;
use App\Domain\Settings\Services\StaffUserRules;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class CreateStaffUser
{
    public function __construct(
        private readonly StaffUserRules $rules,
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, StaffUserData $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $this->rules->assertValid($data);

        return $this->causer->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $data): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'mobile' => $data->mobile,
                'locale' => $data->locale,
                'password' => $data->password,
            ]);

            $user->syncRoles($data->roleNames());

            activity('users')->performedOn($user)->withProperties(['roles' => $data->roleNames()])->log('created');

            $this->notifier->accessChanged($actor, 'user_roles', fn (): array => [
                'name' => $user->name,
                'roles' => implode(', ', array_map(fn (Role $role): string => $role->getLabel(), $data->roles)),
            ], UserResource::getUrl('edit', ['record' => $user], panel: 'admin'));

            return $user;
        }, attempts: 3));
    }
}
