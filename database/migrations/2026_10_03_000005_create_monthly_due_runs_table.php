<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which months have been generated (BR-1, BR-7). Recorded explicitly because a month can be
     * generated without creating anything new, e.g. when every member prepaid it at a locked rate.
     */
    public function up(): void
    {
        Schema::create('monthly_due_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('month')->unique();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('runs')->default(1);
            $table->unsignedInteger('created_dues')->default(0);
            $table->bigInteger('created_amount_poisha')->default(0);
            $table->timestampTz('first_run_at');
            $table->timestampTz('last_run_at');
        });

        DB::statement('ALTER TABLE monthly_due_runs ADD CONSTRAINT monthly_due_runs_first_day CHECK (EXTRACT(DAY FROM month) = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_due_runs');
    }
};
