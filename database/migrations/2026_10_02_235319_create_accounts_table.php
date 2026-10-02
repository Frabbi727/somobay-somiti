<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 4)->unique();
            $table->string('name_en');
            $table->string('name_bn');
            $table->string('type', 16);
            $table->string('normal_balance', 8);
            $table->boolean('is_control')->default(false);
            $table->boolean('requires_member')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });

        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_code_format CHECK (code ~ '^[1-9][0-9]{3}$')");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_valid CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_valid CHECK (normal_balance IN ('debit', 'credit'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
