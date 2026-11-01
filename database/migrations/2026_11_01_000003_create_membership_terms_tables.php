<?php

declare(strict_types=1);

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The somiti's rules and the member's declaration, versioned. A published version never changes;
 * each member's acceptance of a version is an insert-only row. Version 1 is the paper form's text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_terms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->nullable()->unique();
            $table->string('status', 20);
            $table->text('rules_bn');
            $table->text('rules_en');
            $table->text('declaration_bn');
            $table->text('declaration_en');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('terms_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('terms_id')->constrained('membership_terms')->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('member_applications')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('ip', 45)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestampTz('accepted_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE membership_terms
                ADD CONSTRAINT membership_terms_status_valid CHECK (status IN ('draft', 'published')),
                ADD CONSTRAINT membership_terms_version_when_published CHECK ((status = 'published') = (version IS NOT NULL)),
                ADD CONSTRAINT membership_terms_texts_not_blank CHECK (
                    length(trim(rules_bn)) > 0 AND length(trim(rules_en)) > 0
                    AND length(trim(declaration_bn)) > 0 AND length(trim(declaration_en)) > 0);

            CREATE UNIQUE INDEX membership_terms_one_draft ON membership_terms ((true)) WHERE status = 'draft';

            CREATE OR REPLACE FUNCTION membership_terms_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published terms version % cannot be changed or deleted.', OLD.version USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER membership_terms_guard BEFORE UPDATE OR DELETE ON membership_terms
                FOR EACH ROW EXECUTE FUNCTION membership_terms_guard();

            ALTER TABLE terms_acceptances
                ADD CONSTRAINT terms_acceptances_channel_valid CHECK (channel IN ('portal', 'app', 'office')),
                ADD CONSTRAINT terms_acceptances_one_subject CHECK ((member_id IS NULL) <> (application_id IS NULL));

            CREATE UNIQUE INDEX terms_acceptances_member_unique ON terms_acceptances (terms_id, member_id) WHERE member_id IS NOT NULL;
            CREATE UNIQUE INDEX terms_acceptances_application_unique ON terms_acceptances (terms_id, application_id) WHERE application_id IS NOT NULL;

            CREATE TRIGGER terms_acceptances_append_only BEFORE UPDATE OR DELETE ON terms_acceptances
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);

        /** @var array{rules_bn: string, rules_en: string, declaration_bn: string, declaration_en: string} $v1 */
        $v1 = require database_path('data/membership_terms_v1.php');

        DB::table('membership_terms')->insert([
            'version' => 1,
            'status' => 'published',
            'rules_bn' => $v1['rules_bn'],
            'rules_en' => $v1['rules_en'],
            'declaration_bn' => $v1['declaration_bn'],
            'declaration_en' => $v1['declaration_en'],
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // New permission "publish the somiti rules" with its default roles.
        app(RolePermissions::class)->installDefaults();
    }
};
