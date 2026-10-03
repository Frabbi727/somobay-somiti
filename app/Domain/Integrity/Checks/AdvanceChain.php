<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Each member's advance entries chain: balance_after = running sum of deltas, never below zero.
 */
final class AdvanceChain implements IntegrityCheck
{
    public function key(): string
    {
        return 'advance_chain';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT id, member_id FROM (
                SELECT id, member_id, balance_after_poisha, SUM(delta_poisha) OVER (PARTITION BY member_id ORDER BY id) AS running
                FROM advance_ledger_entries
            ) x WHERE running <> balance_after_poisha
            SQL);

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Advance entry {$row->id} of member {$row->member_id} breaks the running balance", ['entry_id' => (int) $row->id]),
            $rows,
        ));
    }
}
