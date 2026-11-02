<?php

declare(strict_types=1);

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An approved investment or top-up entered by mistake is reversed, never deleted: a mirror
     * voucher brings the money back, a "reversal" register entry takes the book value to zero, and
     * the record keeps who reversed it, when and why. Someone other than the approver reverses.
     */
    public function up(): void
    {
        app(RolePermissions::class)->installDefaults();

        foreach (['investments', 'investment_top_ups'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('reversed_at')->nullable();
                $table->text('reversal_reason')->nullable();
                $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            });
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE investments DROP CONSTRAINT investments_status_valid;
            ALTER TABLE investments ADD CONSTRAINT investments_status_valid CHECK (status IN ('pending', 'active', 'rejected', 'cancelled', 'closed', 'reversed'));
            ALTER TABLE investments DROP CONSTRAINT investments_posted_when_approved;
            ALTER TABLE investments ADD CONSTRAINT investments_posted_when_approved CHECK ((status IN ('active', 'closed', 'reversed')) = (journal_entry_id IS NOT NULL));
            ALTER TABLE investments ADD CONSTRAINT investments_reversed_complete CHECK ((status = 'reversed') = (reversed_by IS NOT NULL AND reversal_journal_entry_id IS NOT NULL));
            ALTER TABLE investments ADD CONSTRAINT investments_reverser_differs CHECK (reversed_by IS NULL OR reversed_by <> approved_by);

            ALTER TABLE investment_top_ups DROP CONSTRAINT investment_top_ups_status_valid;
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_status_valid CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'reversed'));
            ALTER TABLE investment_top_ups DROP CONSTRAINT investment_top_ups_posted_when_approved;
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_posted_when_approved CHECK ((status IN ('approved', 'reversed')) = (journal_entry_id IS NOT NULL));
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_reversed_complete CHECK ((status = 'reversed') = (reversed_by IS NOT NULL AND reversal_journal_entry_id IS NOT NULL));
            ALTER TABLE investment_top_ups ADD CONSTRAINT investment_top_ups_reverser_differs CHECK (reversed_by IS NULL OR reversed_by <> approved_by);

            ALTER TABLE investment_ledger_entries DROP CONSTRAINT investment_ledger_kind_valid;
            ALTER TABLE investment_ledger_entries ADD CONSTRAINT investment_ledger_kind_valid CHECK (kind IN ('disbursement', 'top_up', 'impairment', 'closure', 'reversal'));
            SQL);
    }
};
