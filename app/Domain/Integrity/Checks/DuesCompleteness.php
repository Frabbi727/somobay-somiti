<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Every active member has a deposit due (in any status) for each generated month from their dues
 * start month on, while they hold shares or before their shares start (back-dues), and before
 * any exit month. A gap means a month was never billed.
 */
final class DuesCompleteness implements IntegrityCheck
{
    public function key(): string
    {
        return 'dues_completeness';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT m.id AS member_id, r.month
            FROM members m
            JOIN monthly_due_runs r ON r.month >= m.dues_start_month
            WHERE m.status = 'active' AND m.deleted_at IS NULL
              AND (
                  EXISTS (SELECT 1 FROM share_lots l WHERE l.member_id = m.id AND l.effective_from <= r.month AND (l.ended_from IS NULL OR l.ended_from > r.month))
                  OR r.month < (SELECT MIN(l.effective_from) FROM share_lots l WHERE l.member_id = m.id)
              )
              AND NOT EXISTS (SELECT 1 FROM member_exits x WHERE x.member_id = m.id AND x.status <> 'cancelled' AND x.exit_month < r.month)
              AND NOT EXISTS (SELECT 1 FROM dues d WHERE d.member_id = m.id AND d.month = r.month AND d.type = 'deposit')
            ORDER BY m.id, r.month
            SQL);

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Member {$row->member_id} has no deposit due for ".substr((string) $row->month, 0, 7), ['member_id' => (int) $row->member_id]),
            $rows,
        ));
    }
}
