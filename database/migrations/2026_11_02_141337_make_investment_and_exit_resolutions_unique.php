<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One resolution backs one investment or one exit. Rejected and cancelled items release their
     * resolution. Stops with a clear message if existing rows already share a resolution, so they
     * can be looked at before deploying.
     */
    public function up(): void
    {
        $shared = DB::select(<<<'SQL'
            SELECT 'investments' AS tbl, resolution_id FROM investments
            WHERE resolution_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled')
            GROUP BY resolution_id HAVING COUNT(*) > 1
            UNION ALL
            SELECT 'member_exits', resolution_id FROM member_exits
            WHERE resolution_id IS NOT NULL AND status <> 'cancelled'
            GROUP BY resolution_id HAVING COUNT(*) > 1
            SQL);

        if ($shared !== []) {
            $list = implode(', ', array_map(fn (object $row): string => vsprintf('%s: resolution %s', array_values(get_object_vars($row))), $shared));

            throw new RuntimeException("These resolutions back more than one item; fix them before migrating: {$list}");
        }

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX investments_resolution_unique ON investments (resolution_id)
                WHERE resolution_id IS NOT NULL AND status NOT IN ('rejected', 'cancelled');
            CREATE UNIQUE INDEX member_exits_resolution_unique ON member_exits (resolution_id)
                WHERE resolution_id IS NOT NULL AND status <> 'cancelled';
            SQL);
    }
};
