<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A reversed investment releases its resolution (it can be re-entered correctly under the same
     * decision), and an added investment amount needs a resolution of its own. The actions also
     * check across investments and top-ups; these indexes back each table. Stops with a clear message
     * if existing top-ups already share a resolution.
     */
    public function up(): void
    {
        $shared = DB::select(<<<'SQL'
            SELECT resolution_id FROM investment_top_ups
            WHERE resolution_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled', 'reversed')
            GROUP BY resolution_id HAVING COUNT(*) > 1
            SQL);

        if ($shared !== []) {
            $list = implode(', ', array_map(fn (object $row): string => vsprintf('resolution %s', array_values(get_object_vars($row))), $shared));

            throw new RuntimeException("These resolutions back more than one added investment amount; fix them before migrating: {$list}");
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX investments_resolution_unique;
            CREATE UNIQUE INDEX investments_resolution_unique ON investments (resolution_id)
                WHERE resolution_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled', 'reversed');
            CREATE UNIQUE INDEX investment_top_ups_resolution_unique ON investment_top_ups (resolution_id)
                WHERE resolution_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled', 'reversed');
            SQL);
    }
};
