<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit log is append-only: nobody can change or delete what it recorded (SOMITI_SPEC.md §3.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_log_append_only BEFORE UPDATE OR DELETE ON activity_log
            FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);
    }
};
