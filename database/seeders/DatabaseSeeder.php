<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, ChartOfAccountsSeeder::class]);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@somiti.test'],
            ['name' => 'Super Admin', 'password' => 'password'],
        );

        $admin->assignRole(Role::SuperAdmin->value);
    }
}
