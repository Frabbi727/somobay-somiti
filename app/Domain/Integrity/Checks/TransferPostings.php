<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Every approved or reversed fund transfer is posted as recorded: Dr destination (amount),
 * Dr 5104 (charge), Cr source (amount + charge); reversed ones carry their reversal voucher.
 */
final class TransferPostings implements IntegrityCheck
{
    public function key(): string
    {
        return 'transfer_postings';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH codes(method, code) AS (VALUES ('cash', '1101'), ('bank', '1111'), ('bkash', '1121'), ('nagad', '1122'))
            SELECT t.id, t.transfer_no, t.status, t.amount_poisha, t.charge_poisha,
                   COALESCE((SELECT SUM(l.debit_poisha) FROM journal_lines l JOIN accounts a ON a.id = l.account_id
                             WHERE l.journal_entry_id = t.journal_entry_id AND a.code = (SELECT code FROM codes WHERE method = t.to_method)), 0) AS received,
                   COALESCE((SELECT SUM(l.credit_poisha) FROM journal_lines l JOIN accounts a ON a.id = l.account_id
                             WHERE l.journal_entry_id = t.journal_entry_id AND a.code = (SELECT code FROM codes WHERE method = t.from_method)), 0) AS sent,
                   COALESCE((SELECT SUM(l.debit_poisha) FROM journal_lines l JOIN accounts a ON a.id = l.account_id
                             WHERE l.journal_entry_id = t.journal_entry_id AND a.code = '5104'), 0) AS charged,
                   (t.status = 'reversed') AS should_be_reversed,
                   EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_id = t.journal_entry_id AND r.id = t.reversal_journal_entry_id) AS reversed
            FROM fund_transfers t
            WHERE t.status IN ('approved', 'reversed')
            SQL);

        $findings = [];

        foreach ($rows as $row) {
            $amount = (int) $row->amount_poisha;
            $charge = (int) $row->charge_poisha;

            if ((int) $row->received !== $amount || (int) $row->sent !== $amount + $charge || (int) $row->charged !== $charge) {
                $findings[] = new Finding($this->key(), "Transfer {$row->transfer_no}: voucher does not move {$amount} (+{$charge} charge) as recorded", ['transfer_id' => (int) $row->id]);
            }

            if ((bool) $row->should_be_reversed !== (bool) $row->reversed) {
                $findings[] = new Finding($this->key(), "Transfer {$row->transfer_no}: status {$row->status} does not match its vouchers", ['transfer_id' => (int) $row->id]);
            }
        }

        return $findings;
    }
}
