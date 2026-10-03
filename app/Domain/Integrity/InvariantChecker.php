<?php

declare(strict_types=1);

namespace App\Domain\Integrity;

use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Accounting\Services\TrialBalance;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The §6.7 invariants as executable checks. Returns human-readable findings; an empty list means
 * the books tie out to the poisha. Phase 7 schedules this nightly and adds the remaining checks.
 */
final class InvariantChecker
{
    public function __construct(
        private readonly TrialBalance $trialBalance,
        private readonly Reconciliation $reconciliation,
    ) {}

    /**
     * @return list<string>
     */
    public function findings(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::parse('9999-12-31');

        return [
            ...$this->trialBalanceFindings($asOf),
            ...$this->controlAccountFindings($asOf),
            ...$this->paymentFindings(),
            ...$this->dueFindings(),
            ...$this->advanceChainFindings(),
            ...$this->voucherSequenceFindings(),
        ];
    }

    /**
     * @return list<string>
     */
    private function trialBalanceFindings(CarbonImmutable $asOf): array
    {
        $report = $this->trialBalance->asOf($asOf);

        return $report->isBalanced() ? [] : ['Trial balance is out by '.$report->difference()->format('en')];
    }

    /**
     * @return list<string>
     */
    private function controlAccountFindings(CarbonImmutable $asOf): array
    {
        $findings = [];

        foreach ($this->reconciliation->controlVsSubledger($asOf) as $check) {
            if (! $check->isReconciled()) {
                $findings[] = sprintf('Control account %s differs from its sub-ledger by %s', $check->account->code, $check->difference()->format('en'));
            }
        }

        return $findings;
    }

    /**
     * Invariant 3: for each approved payment, allocations + advance held = amount.
     *
     * @return list<string>
     */
    private function paymentFindings(): array
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
                $findings[] = "Payment {$row->id}: allocations {$row->allocated} + advance {$row->held} ≠ amount {$row->amount_poisha}";
            }
        }

        return $findings;
    }

    /**
     * Invariant 4: each due's paid amount equals what approved payments and net advance applications put on it.
     *
     * @return list<string>
     */
    private function dueFindings(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT d.id, d.paid_poisha,
                   COALESCE((SELECT SUM(a.amount_poisha) FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
                             WHERE a.due_id = d.id AND p.status = ?), 0)
                 - COALESCE((SELECT SUM(e.delta_poisha) FROM advance_ledger_entries e
                             WHERE e.due_id = d.id AND e.kind IN (?, ?)), 0) AS expected
            FROM dues d
            SQL, [PaymentStatus::Approved->value, AdvanceEntryKind::AppliedToDue->value, AdvanceEntryKind::Reversal->value]);

        $findings = [];

        foreach ($rows as $row) {
            if ((int) $row->paid_poisha !== (int) $row->expected) {
                $findings[] = "Due {$row->id}: paid {$row->paid_poisha} but allocations say {$row->expected}";
            }
        }

        return $findings;
    }

    /**
     * Each member's advance entries must chain: balance_after = previous balance + delta.
     *
     * @return list<string>
     */
    private function advanceChainFindings(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT id, member_id, balance_after_poisha,
                   SUM(delta_poisha) OVER (PARTITION BY member_id ORDER BY id) AS running
            FROM advance_ledger_entries
            SQL);

        $findings = [];

        foreach ($rows as $row) {
            if ((int) $row->running !== (int) $row->balance_after_poisha) {
                $findings[] = "Advance entry {$row->id} of member {$row->member_id} breaks the running balance";
            }
        }

        return $findings;
    }

    /**
     * Invariant 6: voucher numbers are gap-free per fiscal year and type.
     *
     * @return list<string>
     */
    private function voucherSequenceFindings(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT s.fiscal_year_id, s.voucher_type, s.last_number, COUNT(e.id) AS used
            FROM voucher_sequences s
            LEFT JOIN journal_entries e ON e.fiscal_year_id = s.fiscal_year_id AND e.voucher_type = s.voucher_type
            GROUP BY s.fiscal_year_id, s.voucher_type, s.last_number
            SQL);

        $findings = [];

        foreach ($rows as $row) {
            if ((int) $row->used !== (int) $row->last_number) {
                $findings[] = "Voucher sequence {$row->voucher_type}/{$row->fiscal_year_id}: {$row->used} entries for {$row->last_number} numbers";
            }
        }

        return $findings;
    }
}
