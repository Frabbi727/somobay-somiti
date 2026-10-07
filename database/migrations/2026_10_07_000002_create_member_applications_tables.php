<?php

declare(strict_types=1);

use App\Domain\Notifications\Models\SmsTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member self-registration (spec 2026-10-07 §5): the applicant's draft, nominees and the
     * insert-only decision log. No member, due or journal exists until the final approval.
     */
    public function up(): void
    {
        Schema::create('member_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('mobile', 11);
            $table->string('status', 10)->default('invited');
            $table->string('name_bn')->nullable();
            $table->string('name_en')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('nid', 17)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedInteger('requested_shares')->nullable();
            $table->jsonb('approval_chain')->nullable();
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->unsignedInteger('submission_no')->default(0);
            $table->uuid('submit_idempotency_key')->nullable()->unique();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_step']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_applications
                ADD CONSTRAINT member_applications_status_valid CHECK (status IN ('invited', 'submitted', 'returned', 'approved', 'rejected')),
                ADD CONSTRAINT member_applications_mobile_format CHECK (mobile ~ '^01[3-9][0-9]{8}$'),
                ADD CONSTRAINT member_applications_nid_format CHECK (nid IS NULL OR nid ~ '^([0-9]{10}|[0-9]{13}|[0-9]{17})$'),
                ADD CONSTRAINT member_applications_shares_positive CHECK (requested_shares IS NULL OR requested_shares > 0),
                -- Final approval marks the application approved first (so the mobile is free for
                -- CreateMember), then links the member in the same transaction.
                ADD CONSTRAINT member_applications_member_only_when_approved CHECK (member_id IS NULL OR status = 'approved');

            CREATE UNIQUE INDEX member_applications_one_open_per_mobile ON member_applications (mobile)
                WHERE status IN ('invited', 'submitted', 'returned');
            SQL);

        Schema::create('member_application_nominees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('member_applications')->restrictOnDelete();
            $table->string('name');
            $table->foreignId('relation_id')->nullable()->constrained('nominee_relations')->restrictOnDelete();
            $table->string('mobile', 11)->nullable();
            $table->string('nid', 17)->nullable();
            $table->unsignedSmallInteger('share_bps');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_application_nominees
                ADD CONSTRAINT member_application_nominees_share_valid CHECK (share_bps BETWEEN 0 AND 10000),
                ADD CONSTRAINT member_application_nominees_nid_format CHECK (nid IS NULL OR nid ~ '^([0-9]{10}|[0-9]{13}|[0-9]{17})$');
            SQL);

        Schema::create('member_application_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('member_applications')->restrictOnDelete();
            $table->unsignedInteger('submission_no');
            $table->unsignedSmallInteger('step');
            $table->string('role', 20);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision', 10);
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['application_id', 'submission_no', 'step']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_application_decisions
                ADD CONSTRAINT member_application_decisions_valid CHECK (decision IN ('approve', 'return', 'reject')),
                ADD CONSTRAINT member_application_decisions_reason CHECK (decision = 'approve' OR length(trim(coalesce(reason, ''))) >= 5);

            CREATE TRIGGER member_application_decisions_append_only BEFORE UPDATE OR DELETE ON member_application_decisions
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);

        Schema::table('somiti_profiles', function (Blueprint $table): void {
            $table->jsonb('registration_approval_roles')->default(DB::raw("'[\"secretary\",\"president\"]'::jsonb"));
        });

        foreach ([
            'registration_returned' => [
                '{somiti}: আপনার নিবন্ধন সংশোধনের জন্য ফেরত পাঠানো হয়েছে। কারণ: {reason}। অ্যাপ বা {portal_url} থেকে ঠিক করে আবার জমা দিন।',
                '{somiti}: your registration was sent back for correction. Reason: {reason}. Please fix it in the app or at {portal_url} and submit again.',
            ],
            'registration_rejected' => [
                '{somiti}: দুঃখিত, আপনার সদস্য নিবন্ধন গ্রহণ করা হয়নি। কারণ: {reason}। বিস্তারিত জানতে অফিসে যোগাযোগ করুন।',
                '{somiti}: sorry, your membership registration was not accepted. Reason: {reason}. Please contact the office.',
            ],
        ] as $key => [$bn, $en]) {
            SmsTemplate::query()->firstOrCreate(['key' => $key], ['body_bn' => $bn, 'body_en' => $en, 'is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('somiti_profiles', fn (Blueprint $table) => $table->dropColumn('registration_approval_roles'));
        Schema::dropIfExists('member_application_decisions');
        Schema::dropIfExists('member_application_nominees');
        Schema::dropIfExists('member_applications');
    }
};
