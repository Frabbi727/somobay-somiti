<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member exit (Phase 12, W9): request → freeze → settlement to 2301 on approval → payout,
     * to the member or split between the nominees.
     */
    public function up(): void
    {
        if (Account::query()->exists()) {
            (new ChartOfAccountsSeeder)->run();
        }

        DB::statement('ALTER TABLE advance_ledger_entries DROP CONSTRAINT advance_entries_kind_valid');
        DB::statement("ALTER TABLE advance_ledger_entries ADD CONSTRAINT advance_entries_kind_valid CHECK (kind IN ('payment_surplus', 'applied_to_due', 'refund', 'reversal', 'fee_waiver', 'exit_transfer', 'exit_settlement'))");

        DB::statement('CREATE SEQUENCE IF NOT EXISTS exit_no_seq START 1');

        Schema::create('member_exits', function (Blueprint $table): void {
            $table->id();
            $table->string('exit_no', 12)->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('reason_type', 12);
            $table->text('reason');
            $table->date('exit_month');
            $table->unsignedBigInteger('exit_fee_poisha')->default(0);
            $table->string('status', 12)->default('requested');
            $table->unsignedBigInteger('savings_poisha')->nullable();
            $table->unsignedBigInteger('advance_poisha')->nullable();
            $table->unsignedBigInteger('dividends_poisha')->nullable();
            $table->unsignedBigInteger('receivables_poisha')->nullable();
            $table->unsignedBigInteger('released_poisha')->nullable();
            $table->unsignedBigInteger('net_poisha')->nullable();
            $table->foreignId('resolution_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('settlement_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('payout_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE member_exits ADD CONSTRAINT member_exits_status_valid CHECK (status IN ('requested', 'approved', 'paid', 'cancelled'))");
        DB::statement("ALTER TABLE member_exits ADD CONSTRAINT member_exits_reason_valid CHECK (reason_type IN ('voluntary', 'deceased', 'expelled'))");
        DB::statement('ALTER TABLE member_exits ADD CONSTRAINT member_exits_month_first_day CHECK (EXTRACT(DAY FROM exit_month) = 1)');
        DB::statement("ALTER TABLE member_exits ADD CONSTRAINT member_exits_approved_settled CHECK ((status IN ('approved', 'paid')) = (net_poisha IS NOT NULL AND approved_at IS NOT NULL))");
        DB::statement("ALTER TABLE member_exits ADD CONSTRAINT member_exits_paid_has_payout CHECK ((status = 'paid') = (paid_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX member_exits_one_open ON member_exits (member_id) WHERE status <> 'cancelled'");

        Schema::create('member_exit_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_exit_id')->constrained()->restrictOnDelete();
            $table->foreignId('nominee_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payee');
            $table->unsignedInteger('share_bps');
            $table->unsignedBigInteger('amount_poisha');
            $table->string('paid_from', 10);
            $table->timestampTz('created_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION member_exits_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Exits are never deleted.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status IN ('paid', 'cancelled') THEN
                    RAISE EXCEPTION 'Exit % is % and cannot change.', OLD.exit_no, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'approved' AND (NEW.status <> 'paid'
                    OR (NEW.net_poisha, NEW.savings_poisha, NEW.advance_poisha, NEW.dividends_poisha, NEW.receivables_poisha, NEW.exit_fee_poisha, NEW.settlement_journal_entry_id)
                       IS DISTINCT FROM
                       (OLD.net_poisha, OLD.savings_poisha, OLD.advance_poisha, OLD.dividends_poisha, OLD.receivables_poisha, OLD.exit_fee_poisha, OLD.settlement_journal_entry_id)) THEN
                    RAISE EXCEPTION 'An approved exit only moves on to paid.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER member_exits_guard BEFORE UPDATE OR DELETE ON member_exits
                FOR EACH ROW EXECUTE FUNCTION member_exits_guard();

            CREATE TRIGGER member_exit_payouts_append_only BEFORE UPDATE OR DELETE ON member_exit_payouts
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_exit_payouts');
        Schema::dropIfExists('member_exits');
        DB::unprepared('DROP FUNCTION IF EXISTS member_exits_guard() CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS exit_no_seq');
        DB::statement('ALTER TABLE advance_ledger_entries DROP CONSTRAINT advance_entries_kind_valid');
        DB::statement("ALTER TABLE advance_ledger_entries ADD CONSTRAINT advance_entries_kind_valid CHECK (kind IN ('payment_surplus', 'applied_to_due', 'refund', 'reversal', 'fee_waiver'))");
    }
};
