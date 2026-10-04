<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Statements;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Accounting\Services\Accounts;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statement vs books for one import: closing balances, and what each side has that the other lacks.
 */
final class StatementReconciliation
{
    public function __construct(private readonly Accounts $accounts) {}

    /**
     * @return array{statement_balance: Money|null, book_balance: Money, difference: Money|null, unmatched_statement: Money, unmatched_count: int, book_only: Collection<int, JournalLine>}
     */
    public function summary(StatementImport $import): array
    {
        $account = $this->accounts->byCode($import->method->accountCode());
        $until = $import->period_to?->toDateString() ?? '9999-12-31';

        $bookBalance = Money::ofPoisha((int) DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id)
            ->where('e.entry_date', '<=', $until)
            ->sum(DB::raw('l.debit_poisha - l.credit_poisha')));

        $unmatched = StatementLine::query()
            ->where('statement_import_id', $import->id)
            ->where('status', StatementLineStatus::Unmatched);

        $bookOnly = JournalLine::query()
            ->with('entry')
            ->where('account_id', $account->id)
            ->whereNotIn('id', StatementLine::query()->whereNotNull('journal_line_id')->select('journal_line_id'))
            ->whereHas('entry', fn (Builder $query) => $query->whereBetween('entry_date', [
                $import->period_from?->toDateString() ?? '0001-01-01',
                $until,
            ]))
            ->orderBy('id')
            ->get();

        return [
            'statement_balance' => $import->closing_balance_poisha,
            'book_balance' => $bookBalance,
            'difference' => $import->closing_balance_poisha?->minus($bookBalance),
            'unmatched_statement' => Money::ofPoisha((int) (clone $unmatched)->sum('amount_poisha')),
            'unmatched_count' => (clone $unmatched)->count(),
            'book_only' => $bookOnly,
        ];
    }
}
