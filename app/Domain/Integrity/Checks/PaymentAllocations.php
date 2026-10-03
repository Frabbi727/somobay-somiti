<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 3: every approved payment is fully accounted for by allocations plus advance held.
 */
final class PaymentAllocations implements IntegrityCheck
{
    public function key(): string
    {
        return 'payment_allocations';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.id, p.amount_poisha,
                   COALESCE((SELECT SUM(a.amount_poisha) FROM payment_allocations a WHERE a.payment_id = p.id), 0) AS allocated,
                   COALESCE((SELECT SUM(e.delta_poisha) FROM advance_ledger_entries e WHERE e.payment_id = p.id AND e.kind = ?), 0) AS held
            FROM payments p
            WHERE p.status = ?
            SQL, [AdvanceEntryKind::PaymentSurplus->value, PaymentStatus::Approved->value]);

        $findings = [];

        foreach ($rows as $row) {
            if ((int) $row->allocated + (int) $row->held !== (int) $row->amount_poisha) {
                $findings[] = new Finding($this->key(), "Payment {$row->id}: allocations {$row->allocated} + advance {$row->held} ≠ amount {$row->amount_poisha}", ['payment_id' => (int) $row->id]);
            }
        }

        return $findings;
    }
}
