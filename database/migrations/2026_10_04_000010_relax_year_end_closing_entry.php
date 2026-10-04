<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A year with no income or expense lines has no closing entry; a posted year-end only needs
     * its posting time.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE year_ends DROP CONSTRAINT year_ends_posted_has_entries');
        DB::statement("ALTER TABLE year_ends ADD CONSTRAINT year_ends_posted_has_entries CHECK (status <> 'posted' OR posted_at IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE year_ends DROP CONSTRAINT year_ends_posted_has_entries');
        DB::statement("ALTER TABLE year_ends ADD CONSTRAINT year_ends_posted_has_entries CHECK (status <> 'posted' OR (closing_journal_entry_id IS NOT NULL AND posted_at IS NOT NULL))");
    }
};
