<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('shows the admin login page', function (): void {
    $this->get('/admin/login')->assertOk();
});

it('lets staff into the admin panel', function (Role $role): void {
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $this->actingAs($user)->get('/admin')->assertOk();
})->with(fn (): array => array_map(
    fn (string $role): Role => Role::from($role),
    Role::staff(),
));

it('keeps members and role-less users out of the admin panel', function (?Role $role): void {
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role->value);
    }

    $this->actingAs($user)->get('/admin')->assertForbidden();
})->with([
    'member' => Role::Member,
    'no role' => null,
]);
