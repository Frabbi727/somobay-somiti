<?php

declare(strict_types=1);

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The society's own details (name, registration number, address, logo) for receipts, reports and
 * the panels — one row only (SOMITI_SPEC.md §9 somiti_profiles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('somiti_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name_bn', 200);
            $table->string('name_en', 200);
            $table->string('registration_no', 100)->nullable();
            $table->date('registered_on')->nullable();
            $table->string('address_bn', 500)->nullable();
            $table->string('address_en', 500)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('logo_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE somiti_profiles ADD CONSTRAINT somiti_profiles_single_row CHECK (id = 1)');

        // New permission "edit the society profile" with its default roles.
        app(RolePermissions::class)->installDefaults();
    }
};
