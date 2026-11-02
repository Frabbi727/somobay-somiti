<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * More money put into an active investment (a second payment for a shop, more capital for a
     * business): recorded by one person, approved by another, paid out by voucher and added to the
     * investment's register as a "top_up" entry.
     */
    public function up(): void
    {
        Schema::create('investment_top_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_poisha');
            $table->string('funded_from', 10);
            $table->date('invested_on');
            $table->text('notes')->nullable();
            $table->foreignId('resolution_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('limit_warnings')->nullable();
            $table->string('status', 12)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['investment_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_status_valid CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'));
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_funded_from_valid CHECK (funded_from IN ('cash', 'bank', 'bkash', 'nagad'));
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_amount_positive CHECK (amount_poisha > 0);
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_checker_differs CHECK (approved_by IS NULL OR approved_by <> recorded_by);
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_posted_when_approved CHECK ((status = 'approved') = (journal_entry_id IS NOT NULL));

            ALTER TABLE investment_ledger_entries DROP CONSTRAINT investment_ledger_kind_valid;
            ALTER TABLE investment_ledger_entries ADD CONSTRAINT investment_ledger_kind_valid CHECK (kind IN ('disbursement', 'top_up', 'impairment', 'closure'));
            SQL);
    }
};
