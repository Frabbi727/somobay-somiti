<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PL/pgSQL does not short-circuit across record types, so each table gets its own guard.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS year_ends_guard ON year_ends;
            DROP TRIGGER IF EXISTS dividend_lines_guard ON dividend_lines;
            DROP FUNCTION IF EXISTS year_end_records_guard();

            CREATE OR REPLACE FUNCTION year_ends_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'posted' THEN
                    RAISE EXCEPTION 'A posted year-end cannot change.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION dividend_lines_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Dividend lines are never deleted.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.year_end_id <> OLD.year_end_id OR NEW.member_id <> OLD.member_id
                    OR NEW.share_months <> OLD.share_months OR NEW.amount_poisha <> OLD.amount_poisha
                    OR (OLD.status <> 'unpaid' AND (NEW.status, NEW.settlement_journal_entry_id) IS DISTINCT FROM (OLD.status, OLD.settlement_journal_entry_id)) THEN
                    RAISE EXCEPTION 'Dividend lines are fixed; only an unpaid line can be settled, once.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER year_ends_guard BEFORE UPDATE OR DELETE ON year_ends
                FOR EACH ROW EXECUTE FUNCTION year_ends_guard();
            CREATE TRIGGER dividend_lines_guard BEFORE UPDATE OR DELETE ON dividend_lines
                FOR EACH ROW EXECUTE FUNCTION dividend_lines_guard();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS year_ends_guard ON year_ends;
            DROP TRIGGER IF EXISTS dividend_lines_guard ON dividend_lines;
            DROP FUNCTION IF EXISTS year_ends_guard();
            DROP FUNCTION IF EXISTS dividend_lines_guard();
            SQL);
    }
};
