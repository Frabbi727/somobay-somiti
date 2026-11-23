<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The first month a member owes monthly dues for. Fixed once set: a wrong value is corrected
     * by cancelling the back-dues, never by editing. Existing members: imported ones start at the
     * go-live month (the month after their opening arrears), everyone else at their first share
     * lot, which is what the monthly run has billed them from.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE members ADD COLUMN dues_start_month date NULL;

            UPDATE members m SET dues_start_month = (
                SELECT (MIN(d.month) + INTERVAL '1 month')::date FROM dues d
                WHERE d.member_id = m.id AND d.origin = 'opening'
            )
            WHERE EXISTS (SELECT 1 FROM dues d WHERE d.member_id = m.id AND d.origin = 'opening');

            UPDATE members m SET dues_start_month = (
                SELECT MIN(l.effective_from) FROM share_lots l WHERE l.member_id = m.id
            )
            WHERE dues_start_month IS NULL;

            UPDATE members SET dues_start_month = date_trunc('month', COALESCE(joined_on, created_at))::date
            WHERE dues_start_month IS NULL;

            ALTER TABLE members ALTER COLUMN dues_start_month SET NOT NULL;
            ALTER TABLE members ADD CONSTRAINT members_dues_start_first_day CHECK (EXTRACT(DAY FROM dues_start_month) = 1);

            CREATE OR REPLACE FUNCTION members_dues_start_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.dues_start_month IS DISTINCT FROM OLD.dues_start_month THEN
                    RAISE EXCEPTION 'Member % dues start month is fixed; cancel the back-dues instead.', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER members_dues_start_fixed BEFORE UPDATE ON members
                FOR EACH ROW EXECUTE FUNCTION members_dues_start_guard();
            SQL);
    }
};
