<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Approving an exit now ends the member's shares, recorded as an "exit" share transaction.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE share_transactions DROP CONSTRAINT share_transactions_type_valid;
            ALTER TABLE share_transactions ADD CONSTRAINT share_transactions_type_valid CHECK (type IN ('increase', 'decrease', 'exit'));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE share_transactions DROP CONSTRAINT share_transactions_type_valid;
            ALTER TABLE share_transactions ADD CONSTRAINT share_transactions_type_valid CHECK (type IN ('increase', 'decrease'));
            SQL);
    }
};
