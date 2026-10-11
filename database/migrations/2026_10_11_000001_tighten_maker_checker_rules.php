<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Second-person rules decided on 2026-10-11, backed up in the database:
     * - whoever approved a payment, expense or transfer does not reverse it;
     * - a manual voucher is posted by someone other than whoever prepared or last edited it;
     * - whoever prepared a year-end does not sign it as the accountant;
     * - the super admin holds no permission that approves, reverses or posts money.
     *
     * The CHECKs are NOT VALID so rows written before the rule are left alone; every new or
     * changed row is checked.
     */
    public function up(): void
    {
        Schema::table('journal_drafts', function (Blueprint $table): void {
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_reverser_differs
                CHECK (reversed_by IS NULL OR approved_by IS NULL OR reversed_by <> approved_by) NOT VALID;
            ALTER TABLE expenses ADD CONSTRAINT expenses_reverser_differs
                CHECK (reversed_by IS NULL OR approved_by IS NULL OR reversed_by <> approved_by) NOT VALID;
            ALTER TABLE fund_transfers ADD CONSTRAINT fund_transfers_reverser_differs
                CHECK (reversed_by IS NULL OR approved_by IS NULL OR reversed_by <> approved_by) NOT VALID;
            ALTER TABLE year_ends ADD CONSTRAINT year_ends_preparer_not_accountant_approver
                CHECK (accountant_approved_by IS NULL OR accountant_approved_by <> prepared_by) NOT VALID;

            CREATE OR REPLACE FUNCTION journal_drafts_poster_differs() RETURNS trigger AS $$
            DECLARE
                poster bigint;
            BEGIN
                IF OLD.journal_entry_id IS NULL AND NEW.journal_entry_id IS NOT NULL THEN
                    SELECT posted_by INTO poster FROM journal_entries WHERE id = NEW.journal_entry_id;

                    IF poster = NEW.created_by OR poster = NEW.updated_by THEN
                        RAISE EXCEPTION 'journal draft % must be posted by someone other than its author', NEW.id
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_drafts_poster_differs
                BEFORE UPDATE OF journal_entry_id ON journal_drafts
                FOR EACH ROW EXECUTE FUNCTION journal_drafts_poster_differs();
            SQL);

        $this->revokeCheckerPermissionsFromSuperAdmin();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_drafts_poster_differs ON journal_drafts;
            DROP FUNCTION IF EXISTS journal_drafts_poster_differs();
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_reverser_differs;
            ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_reverser_differs;
            ALTER TABLE fund_transfers DROP CONSTRAINT IF EXISTS fund_transfers_reverser_differs;
            ALTER TABLE year_ends DROP CONSTRAINT IF EXISTS year_ends_preparer_not_accountant_approver;
            SQL);

        Schema::table('journal_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
        });
    }

    /**
     * The defaults never gave these to the super admin; this removes any the president ticked.
     */
    private function revokeCheckerPermissionsFromSuperAdmin(): void
    {
        /** @var array{roles: string, permissions: string, role_has_permissions: string} $tables */
        $tables = config('permission.table_names');

        $checker = array_values(array_map(
            fn (Permission $permission): string => $permission->value,
            array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->isChecker()),
        ));

        $roleId = DB::table($tables['roles'])->where('name', Role::SuperAdmin->value)->value('id');

        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table($tables['permissions'])->whereIn('name', $checker)->pluck('id');

        $removed = DB::table($tables['role_has_permissions'])
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        if ($removed > 0) {
            Log::warning("Removed {$removed} approve/reverse/post permission(s) from the super admin role.");
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
