<?php

declare(strict_types=1);

namespace App\Domain\Investments\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Investments\Models\Investment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The investment register as of a date, by kind, with each kind's 13xx balance beside it.
 */
final class InvestmentRegister
{
    /**
     * @return array{rows: list<array{investment: Investment, book: Money, income: Money}>, groups: list<array{type: InvestmentType, book: Money, ledger: Money}>, total: Money, income: Money}
     */
    public function asOf(CarbonImmutable $date): array
    {
        $day = $date->toDateString();

        $investments = Investment::query()
            ->whereIn('status', [InvestmentStatus::Active, InvestmentStatus::Closed])
            ->where('invested_on', '<=', $day)
            ->orderBy('type')
            ->orderBy('invested_on')
            ->get();

        $books = DB::table('investment_ledger_entries as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.entry_date', '<=', $day)
            ->groupBy('l.investment_id')
            ->selectRaw('l.investment_id, SUM(l.delta_poisha)::bigint AS book')
            ->pluck('book', 'investment_id');

        $incomes = DB::table('investment_income')
            ->where('received_on', '<=', $day)
            ->groupBy('investment_id')
            ->selectRaw('investment_id, SUM(gross_poisha)::bigint AS income')
            ->pluck('income', 'investment_id');

        $rows = [];

        foreach ($investments as $investment) {
            $book = Money::ofPoisha((int) $books->get($investment->id, 0));
            $income = Money::ofPoisha((int) $incomes->get($investment->id, 0));

            if ($book->isZero() && $investment->status === InvestmentStatus::Closed && $income->isZero()) {
                continue;
            }

            $rows[] = ['investment' => $investment, 'book' => $book, 'income' => $income];
        }

        $groups = [];

        foreach (InvestmentType::cases() as $type) {
            $book = Money::sum(array_map(fn (array $row): Money => $row['book'], array_filter($rows, fn (array $row): bool => $row['investment']->type === $type)));
            $account = Account::query()->where('code', $type->accountCode())->first();
            $ledger = $account === null ? Money::zero() : Money::ofPoisha((int) DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('l.account_id', $account->id)
                ->where('e.entry_date', '<=', $day)
                ->sum(DB::raw('l.debit_poisha - l.credit_poisha')));

            if (! $book->isZero() || ! $ledger->isZero()) {
                $groups[] = ['type' => $type, 'book' => $book, 'ledger' => $ledger];
            }
        }

        return [
            'rows' => $rows,
            'groups' => $groups,
            'total' => Money::sum(array_map(fn (array $row): Money => $row['book'], $rows)),
            'income' => Money::sum(array_map(fn (array $row): Money => $row['income'], $rows)),
        ];
    }
}
