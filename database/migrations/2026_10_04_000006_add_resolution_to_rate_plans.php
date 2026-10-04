<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rate plan may be backed by the resolution that adopted it (Phase 9). Once the plan is
     * approved the link is frozen with the rest of the plan; one resolution backs one plan.
     */
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->foreignId('resolution_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rate_plans_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status NOT IN ('draft', 'pending_approval') THEN
                        RAISE EXCEPTION 'Rate plan % is % and cannot be deleted.', OLD.code, OLD.status USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status IN ('cancelled', 'superseded') THEN
                    RAISE EXCEPTION 'Rate plan % is % and immutable.', OLD.code, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'approved' AND (
                    NEW.status NOT IN ('approved', 'cancelled', 'superseded')
                    OR (NEW.code, NEW.effective_from, NEW.is_retroactive, NEW.share_unit_poisha, NEW.service_charge_per_share_poisha,
                        NEW.registration_fee_per_share_poisha, NEW.due_day, NEW.grace_days, NEW.late_fee_mode, NEW.late_fee_fixed_poisha,
                        NEW.late_fee_bps, NEW.late_fee_base, NEW.late_fee_cap_poisha, NEW.late_fee_frequency, NEW.advance_policy,
                        NEW.registration_fee_on_rate_increase, NEW.allocation_order, NEW.approved_at, NEW.created_by, NEW.resolution_id)
                    IS DISTINCT FROM
                       (OLD.code, OLD.effective_from, OLD.is_retroactive, OLD.share_unit_poisha, OLD.service_charge_per_share_poisha,
                        OLD.registration_fee_per_share_poisha, OLD.due_day, OLD.grace_days, OLD.late_fee_mode, OLD.late_fee_fixed_poisha,
                        OLD.late_fee_bps, OLD.late_fee_base, OLD.late_fee_cap_poisha, OLD.late_fee_frequency, OLD.advance_policy,
                        OLD.registration_fee_on_rate_increase, OLD.allocation_order, OLD.approved_at, OLD.created_by, OLD.resolution_id)
                ) THEN
                    RAISE EXCEPTION 'Approved rate plan % is immutable. Create a new version instead.', OLD.code USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rate_plans_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status NOT IN ('draft', 'pending_approval') THEN
                        RAISE EXCEPTION 'Rate plan % is % and cannot be deleted.', OLD.code, OLD.status USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status IN ('cancelled', 'superseded') THEN
                    RAISE EXCEPTION 'Rate plan % is % and immutable.', OLD.code, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'approved' AND (
                    NEW.status NOT IN ('approved', 'cancelled', 'superseded')
                    OR (NEW.code, NEW.effective_from, NEW.is_retroactive, NEW.share_unit_poisha, NEW.service_charge_per_share_poisha,
                        NEW.registration_fee_per_share_poisha, NEW.due_day, NEW.grace_days, NEW.late_fee_mode, NEW.late_fee_fixed_poisha,
                        NEW.late_fee_bps, NEW.late_fee_base, NEW.late_fee_cap_poisha, NEW.late_fee_frequency, NEW.advance_policy,
                        NEW.registration_fee_on_rate_increase, NEW.allocation_order, NEW.approved_at, NEW.created_by)
                    IS DISTINCT FROM
                       (OLD.code, OLD.effective_from, OLD.is_retroactive, OLD.share_unit_poisha, OLD.service_charge_per_share_poisha,
                        OLD.registration_fee_per_share_poisha, OLD.due_day, OLD.grace_days, OLD.late_fee_mode, OLD.late_fee_fixed_poisha,
                        OLD.late_fee_bps, OLD.late_fee_base, OLD.late_fee_cap_poisha, OLD.late_fee_frequency, OLD.advance_policy,
                        OLD.registration_fee_on_rate_increase, OLD.allocation_order, OLD.approved_at, OLD.created_by)
                ) THEN
                    RAISE EXCEPTION 'Approved rate plan % is immutable. Create a new version instead.', OLD.code USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolution_id');
        });
    }
};
