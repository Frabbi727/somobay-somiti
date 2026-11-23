<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The member's position frozen at approval, printed on every copy of the receipt, plus the
     * reason given when a same-day same-amount cash/bank payment is recorded again.
     *
     * Payments that exist now were approved before figures were frozen; the CHECK only applies to
     * payments with a higher id, so their later reversal still works.
     */
    public function up(): void
    {
        $lastExisting = (int) DB::scalar('SELECT COALESCE(MAX(id), 0) FROM payments');

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                ADD COLUMN outstanding_before_poisha bigint NULL,
                ADD COLUMN outstanding_after_poisha bigint NULL,
                ADD COLUMN other_owed_after_poisha bigint NULL,
                ADD COLUMN advance_after_poisha bigint NULL,
                ADD COLUMN paid_through_after date NULL,
                ADD COLUMN duplicate_reason text NULL;

            ALTER TABLE payments ADD CONSTRAINT payments_receipt_figures_nonnegative CHECK (
                COALESCE(outstanding_before_poisha, 0) >= 0 AND COALESCE(outstanding_after_poisha, 0) >= 0
                AND COALESCE(other_owed_after_poisha, 0) >= 0 AND COALESCE(advance_after_poisha, 0) >= 0
            );

            CREATE OR REPLACE FUNCTION payments_receipt_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.outstanding_after_poisha IS NOT NULL AND
                   (NEW.outstanding_before_poisha, NEW.outstanding_after_poisha, NEW.other_owed_after_poisha, NEW.advance_after_poisha, NEW.paid_through_after)
                   IS DISTINCT FROM
                   (OLD.outstanding_before_poisha, OLD.outstanding_after_poisha, OLD.other_owed_after_poisha, OLD.advance_after_poisha, OLD.paid_through_after) THEN
                    RAISE EXCEPTION 'Receipt figures of payment % are frozen.', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payments_receipt_frozen BEFORE UPDATE ON payments
                FOR EACH ROW EXECUTE FUNCTION payments_receipt_guard();
            SQL);

        DB::statement(sprintf(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_receipt_figures_frozen CHECK (
                id <= %d
                OR status NOT IN ('approved', 'reversed')
                OR (outstanding_before_poisha IS NOT NULL AND outstanding_after_poisha IS NOT NULL
                    AND other_owed_after_poisha IS NOT NULL AND advance_after_poisha IS NOT NULL)
            )
            SQL, $lastExisting));
    }
};
