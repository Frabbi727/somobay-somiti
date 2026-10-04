<?php

declare(strict_types=1);

namespace Database\Seeders;

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
        // Roles, one login per role (only the super admin in production), chart of accounts, SMS templates.
        $this->call([RoleSeeder::class, StaffUserSeeder::class, ChartOfAccountsSeeder::class, SmsTemplateSeeder::class]);
    }
}
