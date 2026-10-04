<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money moved between the somiti's own cash, bank and wallets (Phase 8), posted as a contra
     * voucher once a different user approves it. An optional charge goes to 5104.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS transfer_no_seq START 1');

        Schema::create('fund_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no', 12)->unique();
            $table->string('from_method', 10);
            $table->string('to_method', 10);
            $table->unsignedBigInteger('amount_poisha');
            $table->unsignedBigInteger('charge_poisha')->default(0);
            $table->date('transferred_on');
            $table->string('reference', 60)->nullable();
            $table->text('notes')->nullable();
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

            $table->index(['status', 'transferred_on']);
        });

        DB::statement("ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_status_valid CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'reversed'))");
        DB::statement("ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_methods_valid CHECK (from_method IN ('cash', 'bkash', 'nagad', 'bank') AND to_method IN ('cash', 'bkash', 'nagad', 'bank'))");
        DB::statement('ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_distinct_accounts CHECK (from_method <> to_method)');
        DB::statement('ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_amount_positive CHECK (amount_poisha > 0 AND charge_poisha >= 0)');
        DB::statement('ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_checker_differs CHECK (approved_by IS NULL OR approved_by <> recorded_by)');
        DB::statement("ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_posted_when_approved CHECK ((status IN ('approved', 'reversed')) = (journal_entry_id IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_transfers');
        DB::statement('DROP SEQUENCE IF EXISTS transfer_no_seq');
    }
};
