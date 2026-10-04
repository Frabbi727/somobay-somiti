<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12 acceptance: once an exit is paid, the member's savings (2101), advance (2111), unpaid
 * dividend (2201) and exit payable (2301) are all zero, and no open dues remain.
 */
final class ExitedMembersClear implements IntegrityCheck
{
    public function key(): string
    {
        return 'exited_members_clear';
    }

    public function run(): array
    {
        $balances = DB::select(<<<'SQL'
            SELECT m.member_no, a.code, SUM(l.credit_poisha - l.debit_poisha) AS balance
            FROM member_exits x
            JOIN members m ON m.id = x.member_id
            JOIN journal_lines l ON l.member_id = x.member_id
            JOIN accounts a ON a.id = l.account_id
            WHERE x.status = 'paid' AND a.code IN ('2101', '2111', '2201', '2301')
            GROUP BY m.member_no, a.code
            HAVING SUM(l.credit_poisha - l.debit_poisha) <> 0
            SQL);

        $findings = array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Exited member {$row->member_no} still has {$row->balance} on {$row->code}", ['account' => (string) $row->code]),
            $balances,
        );

        $openDues = DB::select(<<<'SQL'
            SELECT m.member_no, COUNT(*) AS open_dues
            FROM member_exits x
            JOIN members m ON m.id = x.member_id
            JOIN dues d ON d.member_id = x.member_id
            WHERE x.status IN ('approved', 'paid') AND d.status = 'open'
            GROUP BY m.member_no
            SQL);

        foreach ($openDues as $row) {
            $findings[] = new Finding($this->key(), "Exited member {$row->member_no} still has {$row->open_dues} open dues", []);
        }

        return array_values($findings);
    }
}
