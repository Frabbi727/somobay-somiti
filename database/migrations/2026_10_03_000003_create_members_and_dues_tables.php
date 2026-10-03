<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Members, nominees and shares (SOMITI_SPEC.md §1.5 BR-1–4, P3), plus the dues table that
     * registration fees are charged into (§6.5). Monthly generation arrives with Phase 4.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS member_no_seq START 1');

        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('member_no', 12)->unique();
            $table->string('name_bn');
            $table->string('name_en');
            $table->string('guardian_name')->nullable();
            $table->string('nid', 17)->nullable()->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('mobile', 11)->unique();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 10)->default('active');
            $table->date('joined_on');
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name_en']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE members
                ADD CONSTRAINT members_status_valid CHECK (status IN ('active', 'inactive', 'exited')),
                ADD CONSTRAINT members_mobile_format CHECK (mobile ~ '^01[3-9][0-9]{8}$'),
                ADD CONSTRAINT members_nid_format CHECK (nid IS NULL OR nid ~ '^([0-9]{10}|[0-9]{13}|[0-9]{17})$');
            SQL);

        Schema::create('nominees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('relation', 50);
            $table->string('mobile', 11)->nullable();
            $table->string('nid', 17)->nullable();
            $table->unsignedSmallInteger('share_bps');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE nominees ADD CONSTRAINT nominees_share_valid CHECK (share_bps BETWEEN 1 AND 10000)');

        Schema::create('share_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('shares');
            $table->date('effective_from');
            $table->date('ended_from')->nullable();
            $table->foreignId('continues_lot_id')->nullable()->constrained('share_lots')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE share_lots
                ADD CONSTRAINT share_lots_shares_positive CHECK (shares > 0),
                ADD CONSTRAINT share_lots_first_day CHECK (EXTRACT(DAY FROM effective_from) = 1 AND (ended_from IS NULL OR EXTRACT(DAY FROM ended_from) = 1)),
                ADD CONSTRAINT share_lots_end_after_start CHECK (ended_from IS NULL OR ended_from > effective_from);
            SQL);

        Schema::create('share_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);
            $table->unsignedInteger('shares');
            $table->unsignedInteger('shares_after');
            $table->date('effective_from');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['member_id', 'effective_from']);
        });

        DB::statement("ALTER TABLE share_transactions ADD CONSTRAINT share_transactions_type_valid CHECK (type IN ('increase', 'decrease'))");

        Schema::create('member_share_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->unsignedInteger('shares');

            $table->unique(['member_id', 'effective_from']);
        });

        Schema::create('dues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->string('type', 16);
            $table->foreignId('share_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('adjustment_seq')->default(0);
            $table->foreignId('rate_plan_id')->constrained()->restrictOnDelete();
            $table->jsonb('snapshot');
            $table->bigInteger('amount_poisha');
            $table->bigInteger('paid_poisha')->default(0);
            $table->date('due_date');
            $table->foreignId('parent_due_id')->nullable()->constrained('dues')->restrictOnDelete();
            $table->boolean('prepaid_locked')->default(false);
            $table->string('status', 10)->default('open');
            $table->text('note')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status', 'month']);
            $table->index(['rate_plan_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE dues
                ADD COLUMN outstanding_poisha bigint GENERATED ALWAYS AS (amount_poisha - paid_poisha) STORED,
                ADD CONSTRAINT dues_natural_key UNIQUE NULLS NOT DISTINCT (member_id, month, type, share_lot_id, adjustment_seq),
                ADD CONSTRAINT dues_amount_positive CHECK (amount_poisha > 0),
                ADD CONSTRAINT dues_paid_within_amount CHECK (paid_poisha >= 0 AND paid_poisha <= amount_poisha),
                ADD CONSTRAINT dues_month_first_day CHECK (EXTRACT(DAY FROM month) = 1),
                ADD CONSTRAINT dues_type_valid CHECK (type IN ('deposit', 'service_charge', 'registration', 'late_fee')),
                ADD CONSTRAINT dues_status_valid CHECK (status IN ('open', 'settled', 'cancelled', 'waived'));

            CREATE OR REPLACE FUNCTION dues_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Dues are never deleted; cancel or waive them.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.member_id, NEW.month, NEW.type, NEW.share_lot_id, NEW.adjustment_seq, NEW.rate_plan_id, NEW.snapshot, NEW.amount_poisha, NEW.due_date, NEW.parent_due_id)
                    IS DISTINCT FROM
                   (OLD.member_id, OLD.month, OLD.type, OLD.share_lot_id, OLD.adjustment_seq, OLD.rate_plan_id, OLD.snapshot, OLD.amount_poisha, OLD.due_date, OLD.parent_due_id) THEN
                    RAISE EXCEPTION 'Due % is immutable apart from payments and status.', OLD.id USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER dues_immutable BEFORE UPDATE OR DELETE ON dues
                FOR EACH ROW EXECUTE FUNCTION dues_guard();
            SQL);

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreign('member_id')->references('id')->on('members')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropForeign(['member_id']);
        });

        Schema::dropIfExists('dues');
        DB::unprepared('DROP FUNCTION IF EXISTS dues_guard();');
        Schema::dropIfExists('member_share_snapshots');
        Schema::dropIfExists('share_transactions');
        Schema::dropIfExists('share_lots');
        Schema::dropIfExists('nominees');
        Schema::dropIfExists('members');
        DB::statement('DROP SEQUENCE IF EXISTS member_no_seq');
    }
};
