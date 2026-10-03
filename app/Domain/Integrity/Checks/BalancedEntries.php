<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Accounting\Services\TrialBalance;
use App\Domain\Integrity\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 1: debits equal credits in every entry and in total.
 */
final class BalancedEntries implements IntegrityCheck
{
    public function __construct(private readonly TrialBalance $trialBalance) {}

    public function key(): string
    {
        return 'balanced_entries';
    }

    public function run(): array
    {
        $findings = [];

        $unbalanced = DB::select(<<<'SQL'
            SELECT e.id, e.voucher_no, COALESCE(SUM(l.debit_poisha), 0) AS debit, COALESCE(SUM(l.credit_poisha), 0) AS credit, COUNT(l.id) AS lines
            FROM journal_entries e LEFT JOIN journal_lines l ON l.journal_entry_id = e.id
            GROUP BY e.id, e.voucher_no
            HAVING COALESCE(SUM(l.debit_poisha), 0) <> COALESCE(SUM(l.credit_poisha), 0) OR COUNT(l.id) < 2
            SQL);

        foreach ($unbalanced as $row) {
            $findings[] = new Finding($this->key(), "Voucher {$row->voucher_no} is unbalanced (debit {$row->debit}, credit {$row->credit}, {$row->lines} lines)", ['journal_entry_id' => (int) $row->id]);
        }

        $report = $this->trialBalance->asOf(CarbonImmutable::parse('9999-12-31'));

        if (! $report->isBalanced()) {
            $findings[] = new Finding($this->key(), 'Trial balance is out by '.$report->difference()->format('en'));
        }

        return $findings;
    }
}
