<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\StaffUserSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds every role and one login for each staff role', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Spatie\Permission\Models\Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::cases())->map->value->sort()->values()->all());

    foreach (StaffUserSeeder::USERS as $email => [, $role]) {
        $user = User::query()->where('email', $email)->sole();

        expect($user->hasAnyOf($role))->toBeTrue()
            ->and(Hash::check(StaffUserSeeder::PASSWORD, $user->password))->toBeTrue();
    }

    // Seeding again changes nothing.
    $this->seed(DatabaseSeeder::class);
    expect(User::query()->count())->toBe(count(StaffUserSeeder::USERS));
});

it('creates only the super admin in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // Called directly: `db:seed` would stop to ask "really run in production?".
    (new StaffUserSeeder)->setContainer(app())->run();

    expect(User::query()->pluck('email')->all())->toBe(['admin@somiti.test']);
});
