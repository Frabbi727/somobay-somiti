<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * A member holds an advance while having open dues of a month already generated: the advance
 * should already have paid them (audit F-06; fixed by re-applying advance after every reversal).
 * A due for a month not generated yet (e.g. the registration fee of shares starting next month)
 * is paid from the advance when that month is generated, so it is not a finding.
 */
final class StrandedAdvance implements IntegrityCheck
{
    public function key(): string
    {
        return 'stranded_advance';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.member_id, a.balance_after_poisha AS advance, SUM(d.outstanding_poisha) AS owed,
                   string_agg(DISTINCT d.type || ' ' || to_char(d.month, 'YYYY-MM') || ' ' || d.origin, ', ') AS kinds
            FROM (
                SELECT DISTINCT ON (member_id) member_id, balance_after_poisha
                FROM advance_ledger_entries ORDER BY member_id, id DESC
            ) a
            JOIN dues d ON d.member_id = a.member_id AND d.status = 'open' AND d.outstanding_poisha > 0
                AND d.month <= (SELECT MAX(month) FROM monthly_due_runs)
            WHERE a.balance_after_poisha > 0
            GROUP BY a.member_id, a.balance_after_poisha
            ORDER BY a.member_id
            SQL);

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Member {$row->member_id} holds {$row->advance} poisha of advance next to {$row->owed} poisha of open dues ({$row->kinds})", ['member_id' => (int) $row->member_id]),
            $rows,
        ));
    }
}
