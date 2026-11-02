<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-12: what an investment is expected to pay (rent, profit) and how often, from a date. Rows are
 * never edited: a new row takes over from its start date, and a newer row with the same start date
 * corrects an older one. Tracking only — nothing here is posted to the books.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_return_expectations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_poisha');
            $table->string('frequency', 12);
            $table->date('starts_on');
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['investment_id', 'starts_on']);
        });

        DB::statement("ALTER TABLE investment_return_expectations ADD CONSTRAINT investment_return_frequency_valid CHECK (frequency IN ('monthly', 'quarterly', 'half_yearly', 'yearly', 'one_time', 'none'))");
        DB::statement("ALTER TABLE investment_return_expectations ADD CONSTRAINT investment_return_amount_matches CHECK ((frequency = 'none' AND amount_poisha = 0) OR (frequency <> 'none' AND amount_poisha > 0))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER investment_return_expectations_append_only BEFORE UPDATE OR DELETE ON investment_return_expectations
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);
    }
};
