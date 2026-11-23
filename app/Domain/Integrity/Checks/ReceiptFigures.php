<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * A frozen receipt must add up: what was owed before, less what this payment put on dues, is
 * what the receipt says was owed after (design 2026-11-22 §4). Prepaid-locked months are left
 * out: approval creates and pays them in one step, so they were never part of "owed before".
 */
final class ReceiptFigures implements IntegrityCheck
{
    public function key(): string
    {
        return 'receipt_figures';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.id, p.outstanding_before_poisha AS before, p.outstanding_after_poisha AS after,
                   COALESCE((SELECT SUM(a.amount_poisha) FROM payment_allocations a JOIN dues d ON d.id = a.due_id WHERE a.payment_id = p.id AND NOT d.prepaid_locked), 0) AS allocated
            FROM payments p
            WHERE p.status = 'approved' AND p.outstanding_after_poisha IS NOT NULL
              AND p.outstanding_before_poisha - COALESCE((SELECT SUM(a.amount_poisha) FROM payment_allocations a JOIN dues d ON d.id = a.due_id WHERE a.payment_id = p.id AND NOT d.prepaid_locked), 0)
                  <> p.outstanding_after_poisha
            ORDER BY p.id
            SQL);

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Receipt of payment {$row->id}: owed before {$row->before} less {$row->allocated} on dues is not the owed after {$row->after}", ['payment_id' => (int) $row->id]),
            $rows,
        ));
    }
}
