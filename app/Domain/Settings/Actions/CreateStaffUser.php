<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\StaffUserData;
use App\Domain\Settings\Services\StaffUserRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class CreateStaffUser
{
    public function __construct(
        private readonly StaffUserRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, StaffUserData $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $this->rules->assertValid($data);

        return $this->causer->withCauser($actor, fn (): User => DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'mobile' => $data->mobile,
                'locale' => $data->locale,
                'password' => $data->password,
            ]);

            $user->syncRoles($data->roleNames());

            activity('users')->performedOn($user)->withProperties(['roles' => $data->roleNames()])->log('created');

            return $user;
        }, attempts: 3));
    }
}
