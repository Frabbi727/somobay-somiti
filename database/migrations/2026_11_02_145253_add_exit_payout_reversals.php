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
     * An exit payout made by mistake (wrong account, wrong payee) is reversed, never deleted: a
     * mirror voucher brings the money back to 2301, a reversal row keeps who, when, why and how
     * much, and the exit returns to "approved, waiting for payout" so it can be paid again. The
     * original payout lines stay, linked to their voucher.
     */
    public function up(): void
    {
        app(RolePermissions::class)->installDefaults();

        Schema::table('member_exit_payouts', function (Blueprint $table): void {
            $table->foreignId('payout_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_exit_payouts DISABLE TRIGGER member_exit_payouts_append_only;
            UPDATE member_exit_payouts p SET payout_journal_entry_id = x.payout_journal_entry_id
                FROM member_exits x WHERE x.id = p.member_exit_id;
            ALTER TABLE member_exit_payouts ENABLE TRIGGER member_exit_payouts_append_only;
            SQL);

        Schema::create('member_exit_payout_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_exit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_poisha');
            $table->foreignId('payout_journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('paid_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('created_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_exit_payout_reversals ADD CONSTRAINT member_exit_payout_reversals_amount_positive CHECK (amount_poisha > 0);
            ALTER TABLE member_exit_payout_reversals ADD CONSTRAINT member_exit_payout_reversals_reverser_differs CHECK (reversed_by <> paid_by);

            CREATE TRIGGER member_exit_payout_reversals_append_only BEFORE UPDATE OR DELETE ON member_exit_payout_reversals
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();

            CREATE OR REPLACE FUNCTION member_exits_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Exits are never deleted.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'cancelled' THEN
                    RAISE EXCEPTION 'Exit % is % and cannot change.', OLD.exit_no, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.net_poisha, NEW.savings_poisha, NEW.advance_poisha, NEW.dividends_poisha, NEW.receivables_poisha, NEW.exit_fee_poisha, NEW.settlement_journal_entry_id)
                       IS DISTINCT FROM
                       (OLD.net_poisha, OLD.savings_poisha, OLD.advance_poisha, OLD.dividends_poisha, OLD.receivables_poisha, OLD.exit_fee_poisha, OLD.settlement_journal_entry_id)
                   AND OLD.status IN ('approved', 'paid') THEN
                    RAISE EXCEPTION 'An approved exit''s settlement never changes.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'paid' THEN
                    -- The only way back from paid: its payout was reversed (a reversal row exists).
                    IF NEW.status <> 'approved' OR NEW.paid_at IS NOT NULL OR NEW.payout_journal_entry_id IS NOT NULL
                       OR OLD.payout_journal_entry_id IS NULL
                       OR NOT EXISTS (SELECT 1 FROM member_exit_payout_reversals r WHERE r.payout_journal_entry_id = OLD.payout_journal_entry_id) THEN
                        RAISE EXCEPTION 'Exit % is paid and cannot change.', OLD.exit_no USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                IF OLD.status = 'approved' AND NEW.status <> 'paid' THEN
                    RAISE EXCEPTION 'An approved exit only moves on to paid.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
