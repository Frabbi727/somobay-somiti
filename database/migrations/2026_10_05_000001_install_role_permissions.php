<?php

declare(strict_types=1);

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Role permissions (Settings › Role permissions): creates every permission with its default roles,
 * so existing installations keep exactly the access they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RolePermissions::class)->installDefaults();
    }
};
