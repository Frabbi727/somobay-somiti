<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Every matched statement line points at a journal line on the same account with the same
 * signed amount (money in = debit, money out = credit).
 */
final class StatementMatches implements IntegrityCheck
{
    public function key(): string
    {
        return 'statement_matches';
    }

    public function run(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH codes(method, code) AS (VALUES ('bank', '1111'), ('bkash', '1121'), ('nagad', '1122'))
            SELECT s.id, s.line_no, s.statement_import_id
            FROM statement_lines s
            JOIN journal_lines l ON l.id = s.journal_line_id
            JOIN accounts a ON a.id = l.account_id
            WHERE a.code <> (SELECT code FROM codes WHERE method = s.method)
               OR s.amount_poisha <> l.debit_poisha - l.credit_poisha
            SQL);

        return array_values(array_map(
            fn (\stdClass $row): Finding => new Finding($this->key(), "Statement {$row->statement_import_id}, line {$row->line_no}, is matched to a book entry with a different account or amount", ['statement_line_id' => (int) $row->id]),
            $rows,
        ));
    }
}
