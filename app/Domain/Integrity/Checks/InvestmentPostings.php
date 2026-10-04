<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Every investment register entry is exactly its voucher's net movement on that investment's
 * 13xx account, and active investments never have a negative book value.
 */
final class InvestmentPostings implements IntegrityCheck
{
    public function key(): string
    {
        return 'investment_postings';
    }

    public function run(): array
    {
        $findings = [];

        $mismatches = DB::select(<<<'SQL'
            SELECT i.investment_no, le.id, le.delta_poisha,
                   COALESCE((SELECT SUM(l.debit_poisha - l.credit_poisha) FROM journal_lines l
                             WHERE l.journal_entry_id = le.journal_entry_id AND l.account_id = i.account_id), 0) AS posted
            FROM investment_ledger_entries le
            JOIN investments i ON i.id = le.investment_id
            WHERE le.delta_poisha <> COALESCE((SELECT SUM(l.debit_poisha - l.credit_poisha) FROM journal_lines l
                                                WHERE l.journal_entry_id = le.journal_entry_id AND l.account_id = i.account_id), 0)
            SQL);

        foreach ($mismatches as $row) {
            $findings[] = new Finding($this->key(), "Investment {$row->investment_no}: register entry {$row->delta_poisha} but its voucher moved {$row->posted}", ['entry_id' => (int) $row->id]);
        }

        $negative = DB::select(<<<'SQL'
            SELECT i.investment_no, SUM(le.delta_poisha) AS book
            FROM investments i JOIN investment_ledger_entries le ON le.investment_id = i.id
            GROUP BY i.id, i.investment_no
            HAVING SUM(le.delta_poisha) < 0 OR (MIN(i.status) = 'closed' AND SUM(le.delta_poisha) <> 0)
            SQL);

        foreach ($negative as $row) {
            $findings[] = new Finding($this->key(), "Investment {$row->investment_no}: book value {$row->book} is not possible for its status", []);
        }

        return $findings;
    }
}
