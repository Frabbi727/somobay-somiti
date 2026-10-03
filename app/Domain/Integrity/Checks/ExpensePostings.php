<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Every approved or reversed expense is posted exactly as recorded: its voucher debits the
 * expense account and credits the paying account by the expense amount; reversed ones are
 * mirrored by their reversal voucher.
 */
final class ExpensePostings implements IntegrityCheck
{
    public function key(): string
    {
        return 'expense_postings';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT x.id, x.expense_no, x.status,
                   COALESCE((SELECT SUM(l.debit_poisha) FROM journal_lines l WHERE l.journal_entry_id = x.journal_entry_id AND l.account_id = x.account_id), 0) AS debited,
                   COALESCE((SELECT SUM(l.credit_poisha) FROM journal_lines l JOIN accounts a ON a.id = l.account_id
                             WHERE l.journal_entry_id = x.journal_entry_id
                               AND a.code = CASE x.paid_from WHEN 'cash' THEN '1101' WHEN 'bank' THEN '1111' WHEN 'bkash' THEN '1121' ELSE '1122' END), 0) AS credited,
                   x.amount_poisha,
                   (x.status = 'reversed') AS should_be_reversed,
                   EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_id = x.journal_entry_id AND r.id = x.reversal_journal_entry_id) AS reversed
            FROM expenses x
            WHERE x.status IN ('approved', 'reversed')
            SQL);

        $findings = [];

        foreach ($rows as $row) {
            if ((int) $row->debited !== (int) $row->amount_poisha || (int) $row->credited !== (int) $row->amount_poisha) {
                $findings[] = new Finding($this->key(), "Expense {$row->expense_no}: voucher does not post {$row->amount_poisha} from the paying account to the expense account", ['expense_id' => (int) $row->id]);
            }

            if ((bool) $row->should_be_reversed !== (bool) $row->reversed) {
                $findings[] = new Finding($this->key(), "Expense {$row->expense_no}: status {$row->status} does not match its vouchers", ['expense_id' => (int) $row->id]);
            }
        }

        return $findings;
    }
}
