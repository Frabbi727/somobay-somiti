<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses (SOMITI_SPEC.md Phase 8): recorded by a maker, approved by a different checker
     * (the president above a threshold), then posted as a payment voucher. Never deleted.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS expense_no_seq START 1');

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense_no', 12)->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('paid_from', 10);
            $table->unsignedBigInteger('amount_poisha');
            $table->date('spent_on');
            $table->string('payee')->nullable();
            $table->string('reference', 60)->nullable();
            $table->text('description');
            $table->string('attachment_path')->nullable();
            $table->string('status', 12)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'spent_on']);
            $table->index(['account_id', 'spent_on']);
        });

        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_valid CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'reversed'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_paid_from_valid CHECK (paid_from IN ('cash', 'bkash', 'nagad', 'bank'))");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount_poisha > 0)');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_checker_differs CHECK (approved_by IS NULL OR approved_by <> recorded_by)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_posted_when_approved CHECK ((status IN ('approved', 'reversed')) = (journal_entry_id IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
        DB::statement('DROP SEQUENCE IF EXISTS expense_no_seq');
    }
};
