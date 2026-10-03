<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 4: each due's paid amount equals what approved payments and net advance applications
 * put on it, and a due is "settled" exactly when nothing is outstanding.
 */
final class DuePaidAmounts implements IntegrityCheck
{
    public function key(): string
    {
        return 'due_paid_amounts';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT d.id, d.paid_poisha, d.outstanding_poisha, d.status,
                   COALESCE((SELECT SUM(a.amount_poisha) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
                             WHERE a.due_id = d.id AND p.status = ?), 0)
                 - COALESCE((SELECT SUM(e.delta_poisha) FROM advance_ledger_entries e
                             WHERE e.due_id = d.id AND e.kind IN (?, ?)), 0) AS expected
            FROM dues d
            SQL, [PaymentStatus::Approved->value, AdvanceEntryKind::AppliedToDue->value, AdvanceEntryKind::Reversal->value]);

        $findings = [];

        foreach ($rows as $row) {
            if ((int) $row->paid_poisha !== (int) $row->expected) {
                $findings[] = new Finding($this->key(), "Due {$row->id}: paid {$row->paid_poisha} but allocations say {$row->expected}", ['due_id' => (int) $row->id]);
            }

            $settled = $row->status === DueStatus::Settled->value;

            if ($settled !== ((int) $row->outstanding_poisha === 0) && in_array($row->status, [DueStatus::Open->value, DueStatus::Settled->value], true)) {
                $findings[] = new Finding($this->key(), "Due {$row->id}: status {$row->status} with {$row->outstanding_poisha} outstanding", ['due_id' => (int) $row->id]);
            }
        }

        return $findings;
    }
}
