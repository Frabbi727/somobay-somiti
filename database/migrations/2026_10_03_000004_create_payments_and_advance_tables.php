<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collections and advances (SOMITI_SPEC.md §5.4, §6.4–6.5, BR-12–16).
     *
     * payment_allocations and advance_ledger_entries are append-only: a reversal is recorded by the
     * payment's status and by opposite advance entries, never by editing rows.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('method', 10);
            $table->bigInteger('amount_poisha');
            $table->string('trx_id', 40)->nullable();
            $table->string('proof_path')->nullable();
            $table->date('received_on');
            $table->string('status', 10)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->string('advance_policy_at_payment', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['method', 'trx_id']);
            $table->index(['status', 'received_on']);
            $table->index(['member_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_method_valid CHECK (method IN ('cash', 'bkash', 'nagad', 'bank')),
                ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'reversed')),
                ADD CONSTRAINT payments_amount_positive CHECK (amount_poisha > 0),
                ADD CONSTRAINT payments_trx_required CHECK (method = 'cash' OR trx_id IS NOT NULL),
                ADD CONSTRAINT payments_policy_valid CHECK (advance_policy_at_payment IS NULL OR advance_policy_at_payment IN ('apply_at_current_rate', 'lock_prepaid_months')),
                ADD CONSTRAINT payments_checker_differs CHECK (approved_by IS NULL OR approved_by <> recorded_by);
            SQL);

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('due_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_poisha');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['payment_id', 'due_id']);
            $table->index('due_id');
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive CHECK (amount_poisha > 0)');

        Schema::create('advance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('delta_poisha');
            $table->bigInteger('balance_after_poisha');
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('due_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('advance_ledger_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['member_id', 'id']);
            $table->index('payment_id');
            $table->index('due_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE advance_ledger_entries
                ADD CONSTRAINT advance_entries_kind_valid CHECK (kind IN ('payment_surplus', 'applied_to_due', 'refund', 'reversal', 'fee_waiver')),
                ADD CONSTRAINT advance_entries_delta_nonzero CHECK (delta_poisha <> 0),
                ADD CONSTRAINT advance_entries_balance_nonnegative CHECK (balance_after_poisha >= 0);

            CREATE OR REPLACE FUNCTION append_only_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% rows are append-only (% rejected).', TG_TABLE_NAME, TG_OP USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_allocations_append_only BEFORE UPDATE OR DELETE ON payment_allocations
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();

            CREATE TRIGGER advance_ledger_entries_append_only BEFORE UPDATE OR DELETE ON advance_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_ledger_entries');
        Schema::dropIfExists('payment_allocations');
        DB::unprepared('DROP FUNCTION IF EXISTS append_only_guard();');
        Schema::dropIfExists('payments');
    }
};
