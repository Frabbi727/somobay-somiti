<?php

declare(strict_types=1);

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-19: the financial settings that used to live only in the server config (president thresholds,
 * quorums, required resolutions, year-end choices). Each row is a complete set of values; one person
 * proposes it, someone else approves it, and the newest approved row is in force. Decided rows never
 * change; only one proposal waits at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_settings_versions', function (Blueprint $table): void {
            $table->id();
            $table->jsonb('settings');
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'decided_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE financial_settings_versions ADD CONSTRAINT financial_settings_status_valid
                CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'));
            ALTER TABLE financial_settings_versions ADD CONSTRAINT financial_settings_decided
                CHECK ((status = 'pending') = (decided_at IS NULL) AND (status IN ('approved', 'rejected')) = (decided_by IS NOT NULL));
            ALTER TABLE financial_settings_versions ADD CONSTRAINT financial_settings_checker_differs
                CHECK (decided_by IS NULL OR decided_by <> proposed_by);
            ALTER TABLE financial_settings_versions ADD CONSTRAINT financial_settings_rejection_reason
                CHECK ((status = 'rejected') = (rejection_reason IS NOT NULL));

            CREATE UNIQUE INDEX financial_settings_one_pending ON financial_settings_versions ((true)) WHERE status = 'pending';

            CREATE OR REPLACE FUNCTION financial_settings_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Financial settings versions are never deleted.' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status <> 'pending' OR NEW.settings IS DISTINCT FROM OLD.settings OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.proposed_by IS DISTINCT FROM OLD.proposed_by THEN
                    RAISE EXCEPTION 'A financial settings version is fixed once proposed and closed once decided.' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER financial_settings_guard BEFORE UPDATE OR DELETE ON financial_settings_versions
                FOR EACH ROW EXECUTE FUNCTION financial_settings_guard();
            SQL);

        app(RolePermissions::class)->installDefaults();
    }
};
