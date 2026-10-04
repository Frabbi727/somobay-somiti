<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Governance (Phase 9): meetings with attendance and quorum, and the resolutions decided at them.
     * A held meeting is a record: its quorum figures are snapshotted and it cannot change afterwards.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS meeting_no_seq START 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS resolution_no_seq START 1');

        Schema::create('meetings', function (Blueprint $table): void {
            $table->id();
            $table->string('meeting_no', 12)->unique();
            $table->string('type', 20);
            $table->string('title');
            $table->timestampTz('scheduled_at');
            $table->string('venue')->nullable();
            $table->text('agenda')->nullable();
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('eligible_count')->nullable();
            $table->unsignedInteger('attendees_count')->nullable();
            $table->unsignedInteger('quorum_required')->nullable();
            $table->boolean('quorum_met')->nullable();
            $table->text('minutes')->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('held_recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_type_valid CHECK (type IN ('committee', 'general', 'special_general'))");
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_status_valid CHECK (status IN ('draft', 'scheduled', 'held', 'cancelled'))");
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_held_snapshot CHECK ((status = 'held') = (held_at IS NOT NULL AND quorum_met IS NOT NULL AND eligible_count IS NOT NULL AND attendees_count IS NOT NULL AND quorum_required IS NOT NULL))");

        Schema::create('meeting_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['meeting_id', 'member_id']);
            $table->unique(['meeting_id', 'user_id']);
        });

        DB::statement('ALTER TABLE meeting_attendees ADD CONSTRAINT meeting_attendees_one_person CHECK ((member_id IS NULL) <> (user_id IS NULL))');

        Schema::create('resolutions', function (Blueprint $table): void {
            $table->id();
            $table->string('resolution_no', 12)->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->string('subject', 20);
            $table->string('title');
            $table->text('body');
            $table->string('majority', 12)->default('simple');
            $table->string('status', 12)->default('proposed');
            $table->unsignedInteger('votes_for')->nullable();
            $table->unsignedInteger('votes_against')->nullable();
            $table->unsignedInteger('votes_abstain')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['subject', 'status']);
        });

        DB::statement("ALTER TABLE resolutions ADD CONSTRAINT resolutions_subject_valid CHECK (subject IN ('rate_plan', 'advance_policy', 'investment', 'year_end', 'exit', 'expense', 'bylaws', 'other'))");
        DB::statement("ALTER TABLE resolutions ADD CONSTRAINT resolutions_majority_valid CHECK (majority IN ('simple', 'two_thirds'))");
        DB::statement("ALTER TABLE resolutions ADD CONSTRAINT resolutions_status_valid CHECK (status IN ('proposed', 'passed', 'rejected', 'withdrawn'))");
        DB::statement("ALTER TABLE resolutions ADD CONSTRAINT resolutions_decided_has_votes CHECK ((status IN ('passed', 'rejected')) = (votes_for IS NOT NULL AND votes_against IS NOT NULL AND votes_abstain IS NOT NULL AND decided_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION governance_records_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'meetings' AND OLD.status IN ('held', 'cancelled') THEN
                    RAISE EXCEPTION 'Meeting % is % and cannot change.', OLD.meeting_no, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_TABLE_NAME = 'resolutions' AND OLD.status IN ('passed', 'rejected', 'withdrawn') THEN
                    RAISE EXCEPTION 'Resolution % is % and cannot change.', OLD.resolution_no, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Governance records are never deleted.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER meetings_guard BEFORE UPDATE OR DELETE ON meetings
                FOR EACH ROW EXECUTE FUNCTION governance_records_guard();
            CREATE TRIGGER resolutions_guard BEFORE UPDATE OR DELETE ON resolutions
                FOR EACH ROW EXECUTE FUNCTION governance_records_guard();

            CREATE OR REPLACE FUNCTION meeting_attendees_guard() RETURNS trigger AS $$
            DECLARE
                meeting_status text;
            BEGIN
                SELECT status INTO meeting_status FROM meetings WHERE id = COALESCE(NEW.meeting_id, OLD.meeting_id);

                IF meeting_status IN ('held', 'cancelled') THEN
                    RAISE EXCEPTION 'Attendance of a % meeting cannot change.', meeting_status USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER meeting_attendees_guard BEFORE INSERT OR UPDATE OR DELETE ON meeting_attendees
                FOR EACH ROW EXECUTE FUNCTION meeting_attendees_guard();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resolutions');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
        DB::unprepared('DROP FUNCTION IF EXISTS meeting_attendees_guard() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS governance_records_guard() CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS resolution_no_seq');
        DB::statement('DROP SEQUENCE IF EXISTS meeting_no_seq');
    }
};
