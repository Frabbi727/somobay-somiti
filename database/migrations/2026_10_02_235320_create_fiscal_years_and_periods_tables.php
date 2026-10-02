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
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('start_year')->unique();
            $table->string('code', 7)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 8)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_dates_valid CHECK (ends_on > starts_on)');
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_status_valid CHECK (status IN ('open', 'closed'))");

        Schema::create('periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->date('month')->unique();
            $table->string('status', 8)->default('open');
            $table->timestampTz('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'sequence']);
        });

        DB::statement('ALTER TABLE periods ADD CONSTRAINT periods_sequence_valid CHECK (sequence BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE periods ADD CONSTRAINT periods_month_first_day CHECK (EXTRACT(DAY FROM month) = 1)');
        DB::statement("ALTER TABLE periods ADD CONSTRAINT periods_status_valid CHECK (status IN ('open', 'locked'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periods');
        Schema::dropIfExists('fiscal_years');
    }
};
