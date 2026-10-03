<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Reports\TrialBalanceReport;
use App\Domain\Accounting\Reports\TrialBalanceRow;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Account balances from all postings up to a date, summed as integers in SQL.
 */
final class TrialBalance
{
    public function asOf(CarbonImmutable $date): TrialBalanceReport
    {
        $totals = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.entry_date', '<=', $date->toDateString())
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit_poisha)::bigint AS debit, SUM(l.credit_poisha)::bigint AS credit')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::withTrashed()
            ->whereIn('id', $totals->keys()->all())
            ->orderBy('code')
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $total = $totals->get($account->id);

            $rows[] = new TrialBalanceRow(
                $account,
                Money::ofPoisha((int) ($total->debit ?? 0)),
                Money::ofPoisha((int) ($total->credit ?? 0)),
            );
        }

        return new TrialBalanceReport($date, $rows);
    }
}
