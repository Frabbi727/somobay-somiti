<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 6: voucher numbers run 1…n without gaps or repeats for each fiscal year and type.
 */
final class VoucherSequences implements IntegrityCheck
{
    public function key(): string
    {
        return 'voucher_sequences';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT s.fiscal_year_id, s.voucher_type, s.last_number,
                   COUNT(e.id) AS used,
                   COUNT(DISTINCT e.voucher_no) AS distinct_numbers,
                   COALESCE(MAX(CAST(RIGHT(e.voucher_no, 6) AS integer)), 0) AS highest
            FROM voucher_sequences s
            LEFT JOIN journal_entries e ON e.fiscal_year_id = s.fiscal_year_id AND e.voucher_type = s.voucher_type
            GROUP BY s.fiscal_year_id, s.voucher_type, s.last_number
            SQL);

        $findings = [];

        foreach ($rows as $row) {
            $n = (int) $row->last_number;

            if ((int) $row->used !== $n || (int) $row->distinct_numbers !== $n || (int) $row->highest !== $n) {
                $findings[] = new Finding($this->key(), "Sequence {$row->voucher_type} of fiscal year {$row->fiscal_year_id}: {$row->used} vouchers, highest {$row->highest}, counter {$n}", ['fiscal_year_id' => (int) $row->fiscal_year_id, 'type' => (string) $row->voucher_type]);
            }
        }

        $orphans = DB::select(<<<'SQL'
            SELECT e.id, e.voucher_no FROM journal_entries e
            WHERE NOT EXISTS (SELECT 1 FROM voucher_sequences s WHERE s.fiscal_year_id = e.fiscal_year_id AND s.voucher_type = e.voucher_type)
               OR e.voucher_no !~ '^(RV|PV|JV|CV)-[0-9]{4}-[0-9]{2}-[0-9]{6}$'
               OR LEFT(e.voucher_no, 2) <> e.voucher_type
            SQL);

        foreach ($orphans as $row) {
            $findings[] = new Finding($this->key(), "Voucher {$row->voucher_no} was not numbered by the voucher sequence", ['journal_entry_id' => (int) $row->id]);
        }

        return $findings;
    }
}
