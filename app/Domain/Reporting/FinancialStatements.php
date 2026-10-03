<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Income statement and balance sheet from posted journal lines (integer SQL sums).
 * Until year-end closing exists (Phase 11), the balance sheet shows the running surplus
 * (income − expenses to date) as its own equity line.
 */
final class FinancialStatements
{
    /**
     * @return array{income: list<array{account: Account, amount: Money}>, expense: list<array{account: Account, amount: Money}>, total_income: Money, total_expense: Money, surplus: Money}
     */
    public function incomeStatement(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $income = $this->balances([AccountType::Income], $from, $until);
        $expense = $this->balances([AccountType::Expense], $from, $until);
        $totalIncome = Money::sum(array_column($income, 'amount'));
        $totalExpense = Money::sum(array_column($expense, 'amount'));

        return [
            'income' => $income,
            'expense' => $expense,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'surplus' => $totalIncome->minus($totalExpense),
        ];
    }

    /**
     * @return array{assets: list<array{account: Account, amount: Money}>, liabilities: list<array{account: Account, amount: Money}>, equity: list<array{account: Account, amount: Money}>, surplus: Money, total_assets: Money, total_liabilities_equity: Money}
     */
    public function balanceSheet(CarbonImmutable $asOf): array
    {
        $assets = $this->balances([AccountType::Asset], null, $asOf);
        $liabilities = $this->balances([AccountType::Liability], null, $asOf);
        $equity = $this->balances([AccountType::Equity], null, $asOf);
        $surplus = Money::sum(array_column($this->balances([AccountType::Income], null, $asOf), 'amount'))
            ->minus(Money::sum(array_column($this->balances([AccountType::Expense], null, $asOf), 'amount')));

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'surplus' => $surplus,
            'total_assets' => Money::sum(array_column($assets, 'amount')),
            'total_liabilities_equity' => Money::sum(array_column($liabilities, 'amount'))
                ->plus(Money::sum(array_column($equity, 'amount')))
                ->plus($surplus),
        ];
    }

    /**
     * Balances on the accounts' normal side (debit-positive for assets/expenses, credit-positive otherwise).
     *
     * @param  list<AccountType>  $types
     * @return list<array{account: Account, amount: Money}>
     */
    private function balances(array $types, ?CarbonImmutable $from, CarbonImmutable $until): array
    {
        $nets = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.type', array_map(fn (AccountType $type): string => $type->value, $types))
            ->when($from !== null, fn ($query) => $query->where('e.entry_date', '>=', $from?->toDateString()))
            ->where('e.entry_date', '<=', $until->toDateString())
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, (SUM(l.debit_poisha) - SUM(l.credit_poisha))::bigint AS net')
            ->pluck('net', 'account_id');

        $rows = [];

        foreach (Account::withTrashed()->whereIn('id', $nets->keys()->all())->orderBy('code')->get() as $account) {
            $net = Money::ofPoisha((int) $nets->get($account->id));
            $amount = in_array($account->type, [AccountType::Asset, AccountType::Expense], true) ? $net : $net->negated();

            if (! $amount->isZero()) {
                $rows[] = ['account' => $account, 'amount' => $amount];
            }
        }

        return $rows;
    }
}
