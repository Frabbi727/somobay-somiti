<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Create every application role and permission for the web guard (new permissions get their default roles).
     */
    public function run(): void
    {
        app(RolePermissions::class)->installDefaults();
    }
}
