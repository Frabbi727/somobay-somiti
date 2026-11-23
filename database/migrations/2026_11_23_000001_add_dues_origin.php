<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Where a due came from: the monthly run (regular), the go-live import (opening) or a late
     * joiner's catch-up months (back_dues). Replaces the opening boolean. Also allows the
     * entry_premium due type and makes origin part of the immutable due record.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE dues ADD COLUMN origin varchar(12) NOT NULL DEFAULT 'regular';
            UPDATE dues SET origin = 'opening' WHERE opening;
            ALTER TABLE dues DROP COLUMN opening;
            ALTER TABLE dues ADD CONSTRAINT dues_origin_valid CHECK (origin IN ('regular', 'opening', 'back_dues'));
            CREATE INDEX dues_member_origin_index ON dues (member_id, origin);

            ALTER TABLE dues DROP CONSTRAINT dues_type_valid;
            ALTER TABLE dues ADD CONSTRAINT dues_type_valid CHECK (type IN ('deposit', 'service_charge', 'registration', 'late_fee', 'entry_premium'));

            CREATE OR REPLACE FUNCTION dues_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Dues are never deleted; cancel or waive them.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.member_id, NEW.month, NEW.type, NEW.share_lot_id, NEW.adjustment_seq, NEW.rate_plan_id, NEW.snapshot, NEW.amount_poisha, NEW.due_date, NEW.parent_due_id, NEW.origin)
                    IS DISTINCT FROM
                   (OLD.member_id, OLD.month, OLD.type, OLD.share_lot_id, OLD.adjustment_seq, OLD.rate_plan_id, OLD.snapshot, OLD.amount_poisha, OLD.due_date, OLD.parent_due_id, OLD.origin) THEN
                    RAISE EXCEPTION 'Due % is immutable apart from payments and status.', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
