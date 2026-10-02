<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Posting rules for the journal (SOMITI_SPEC.md §6.5, BR-17, BR-18):
     *  - posted entries and lines are append-only (triggers block UPDATE and DELETE);
     *  - every entry must balance, checked at COMMIT by deferred constraint triggers;
     *  - voucher numbers come from a row-locked sequence per fiscal year and type;
     *  - manual vouchers are prepared as drafts and only enter the journal when posted.
     *
     * A reversed entry is recognised by another entry pointing at it through reverses_id,
     * so no posted row ever needs to change. The unused status column is therefore dropped.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('status');
            $table->text('reason')->nullable()->after('narration');
        });

        Schema::create('voucher_sequences', function (Blueprint $table): void {
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->string('voucher_type', 2);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->timestamps();

            $table->primary(['fiscal_year_id', 'voucher_type']);
        });

        DB::statement("ALTER TABLE voucher_sequences ADD CONSTRAINT voucher_sequences_type_valid CHECK (voucher_type IN ('RV', 'PV', 'JV', 'CV'))");

        Schema::create('journal_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('voucher_type', 2);
            $table->date('entry_date');
            $table->text('narration');
            $table->jsonb('lines');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE journal_drafts ADD CONSTRAINT journal_drafts_type_valid CHECK (voucher_type IN ('JV', 'CV'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_reject_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Posted journal rows are immutable (% on %). Reverse the entry instead.', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_entries_immutable BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION journal_reject_change();

            CREATE TRIGGER journal_lines_immutable BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_reject_change();

            CREATE OR REPLACE FUNCTION journal_assert_balanced(entry_id bigint) RETURNS void AS $$
            DECLARE
                total_debit bigint;
                total_credit bigint;
                line_count integer;
            BEGIN
                SELECT COALESCE(SUM(debit_poisha), 0), COALESCE(SUM(credit_poisha), 0), COUNT(*)
                    INTO total_debit, total_credit, line_count
                    FROM journal_lines
                    WHERE journal_entry_id = entry_id;

                IF line_count < 2 OR total_debit <> total_credit OR total_debit = 0 THEN
                    RAISE EXCEPTION 'Journal entry % is not balanced (debit %, credit %, % lines).', entry_id, total_debit, total_credit, line_count
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION journal_entries_balanced() RETURNS trigger AS $$
            BEGIN
                PERFORM journal_assert_balanced(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION journal_lines_balanced() RETURNS trigger AS $$
            BEGIN
                PERFORM journal_assert_balanced(NEW.journal_entry_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_entries_must_balance AFTER INSERT ON journal_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entries_balanced();

            CREATE CONSTRAINT TRIGGER journal_lines_must_balance AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_lines_balanced();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_must_balance ON journal_lines;
            DROP TRIGGER IF EXISTS journal_entries_must_balance ON journal_entries;
            DROP TRIGGER IF EXISTS journal_lines_immutable ON journal_lines;
            DROP TRIGGER IF EXISTS journal_entries_immutable ON journal_entries;
            DROP FUNCTION IF EXISTS journal_lines_balanced();
            DROP FUNCTION IF EXISTS journal_entries_balanced();
            DROP FUNCTION IF EXISTS journal_assert_balanced(bigint);
            DROP FUNCTION IF EXISTS journal_reject_change();
            SQL);

        Schema::dropIfExists('journal_drafts');
        Schema::dropIfExists('voucher_sequences');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('reason');
            $table->string('status', 10)->default('posted');
        });
    }
};
