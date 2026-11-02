<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional details beyond the paper form: father's and mother's names, profession and
     * marital status, on members and on registrations (copied across at approval).
     */
    public function up(): void
    {
        foreach (['members', 'member_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('father_name')->nullable();
                $table->string('mother_name')->nullable();
                $table->string('profession')->nullable();
                $table->string('marital_status', 10)->nullable();
            });

            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_marital_status_valid CHECK (marital_status IS NULL OR marital_status IN ('unmarried', 'married', 'widowed', 'divorced'))");
        }
    }
};
