<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time-versioned rate plans (SOMITI_SPEC.md §5.2, BR-5–7).
     *
     * A plan applies from its effective month until a later approved plan starts. Approved
     * plans are immutable: a trigger rejects any change to their rates, and only a status
     * move to superseded/cancelled is allowed. One approved plan per month is enforced by a
     * partial unique index.
     */
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->date('effective_from');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_retroactive')->default(false);
            $table->unsignedBigInteger('share_unit_poisha');
            $table->unsignedBigInteger('service_charge_per_share_poisha')->default(0);
            $table->unsignedBigInteger('registration_fee_per_share_poisha')->default(0);
            $table->unsignedTinyInteger('due_day');
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('late_fee_mode', 10)->default('none');
            $table->unsignedBigInteger('late_fee_fixed_poisha')->nullable();
            $table->unsignedInteger('late_fee_bps')->nullable();
            $table->string('late_fee_base', 24)->nullable();
            $table->unsignedBigInteger('late_fee_cap_poisha')->nullable();
            $table->string('late_fee_frequency', 24)->nullable();
            $table->string('advance_policy', 32)->default('apply_at_current_rate');
            $table->string('registration_fee_on_rate_increase', 12)->default('none');
            $table->jsonb('allocation_order');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('submission_no')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('rate_plans')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE rate_plans
                ADD CONSTRAINT rate_plans_effective_first_day CHECK (EXTRACT(DAY FROM effective_from) = 1),
                ADD CONSTRAINT rate_plans_status_valid CHECK (status IN ('draft', 'pending_approval', 'approved', 'cancelled', 'superseded')),
                ADD CONSTRAINT rate_plans_due_day_valid CHECK (due_day BETWEEN 1 AND 28),
                ADD CONSTRAINT rate_plans_share_unit_positive CHECK (share_unit_poisha > 0),
                ADD CONSTRAINT rate_plans_late_fee_mode_valid CHECK (late_fee_mode IN ('none', 'fixed', 'percent')),
                ADD CONSTRAINT rate_plans_late_fee_consistent CHECK (
                    (late_fee_mode = 'none' AND late_fee_fixed_poisha IS NULL AND late_fee_bps IS NULL)
                    OR (late_fee_mode = 'fixed' AND late_fee_fixed_poisha > 0 AND late_fee_bps IS NULL AND late_fee_frequency IS NOT NULL)
                    OR (late_fee_mode = 'percent' AND late_fee_bps > 0 AND late_fee_fixed_poisha IS NULL AND late_fee_base IS NOT NULL AND late_fee_frequency IS NOT NULL)
                ),
                ADD CONSTRAINT rate_plans_late_fee_base_valid CHECK (late_fee_base IS NULL OR late_fee_base IN ('deposit_only', 'deposit_plus_service', 'outstanding_total')),
                ADD CONSTRAINT rate_plans_late_fee_frequency_valid CHECK (late_fee_frequency IS NULL OR late_fee_frequency IN ('once', 'monthly_until_paid')),
                ADD CONSTRAINT rate_plans_advance_policy_valid CHECK (advance_policy IN ('apply_at_current_rate', 'lock_prepaid_months')),
                ADD CONSTRAINT rate_plans_registration_policy_valid CHECK (registration_fee_on_rate_increase IN ('none', 'difference'));

            CREATE UNIQUE INDEX rate_plans_one_approved_per_month ON rate_plans (effective_from) WHERE status = 'approved';

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

            CREATE TRIGGER rate_plans_immutable BEFORE UPDATE OR DELETE ON rate_plans
                FOR EACH ROW EXECUTE FUNCTION rate_plans_guard();
            SQL);

        Schema::create('rate_plan_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('submission_no');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->string('decision', 10);
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['rate_plan_id', 'submission_no', 'user_id']);
        });

        DB::statement("ALTER TABLE rate_plan_approvals ADD CONSTRAINT rate_plan_approvals_decision_valid CHECK (decision IN ('approve', 'reject'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rate_plan_approvals');
        DB::unprepared('DROP TRIGGER IF EXISTS rate_plans_immutable ON rate_plans; DROP FUNCTION IF EXISTS rate_plans_guard();');
        Schema::dropIfExists('rate_plans');
    }
};
