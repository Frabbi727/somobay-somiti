<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Once an exit is approved the member holds no shares after the exit month: a share lot still
 * running would earn dues and dividends for someone who has been settled and paid out.
 */
final class ExitedMembersHoldNoShares implements IntegrityCheck
{
    public function key(): string
    {
        return 'exited_members_hold_no_shares';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT m.member_no, SUM(l.shares) AS shares
            FROM member_exits x
            JOIN members m ON m.id = x.member_id
            JOIN share_lots l ON l.member_id = x.member_id
            WHERE x.status IN ('approved', 'paid')
              AND (l.ended_from IS NULL OR l.ended_from > (x.exit_month + INTERVAL '1 month'))
            GROUP BY m.member_no
            ORDER BY m.member_no
            SQL);

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Exited member {$row->member_no} still holds {$row->shares} shares after the exit month", ['member_no' => (string) $row->member_no]),
            $rows,
        ));
    }
}
