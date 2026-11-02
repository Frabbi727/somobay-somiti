<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash, bank and wallets cannot go below zero: money that is not there cannot be paid out.
 * Takes a transaction-level advisory lock per account, so two approvals cannot both spend the
 * same balance. Call inside the transaction that posts the outflow.
 *
 * The outflow is dated: it must be covered by the closing balance of its own day and of every
 * later day already posted, so a back-dated payout cannot make a past balance negative.
 */
final class FundsGuard
{
    public function assertCovers(Account $account, Money $outflow, CarbonImmutable $on): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7101, $account->id]);

        $available = $this->lowestBalanceFrom($account, $on);

        if ($available->isLessThan($outflow)) {
            throw DomainRuleViolation::because('accounting.errors.insufficient_funds', [
                'account' => $account->displayName(),
                'balance' => $available->format(app()->getLocale()),
                'amount' => $outflow->format(app()->getLocale()),
            ]);
        }
    }

    /**
     * The smallest end-of-day balance from $on onwards (the balance on $on itself when nothing
     * is posted later).
     */
    public function lowestBalanceFrom(Account $account, CarbonImmutable $on): Money
    {
        $day = $on->toDateString();
        $lines = fn () => DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id);

        $running = (int) $lines()->where('e.entry_date', '<', $day)->sum(DB::raw('l.debit_poisha - l.credit_poisha'));

        $daily = $lines()
            ->where('e.entry_date', '>=', $day)
            ->groupBy('e.entry_date')
            ->orderBy('e.entry_date')
            ->selectRaw('e.entry_date, SUM(l.debit_poisha - l.credit_poisha)::bigint AS delta')
            ->get();

        $lowest = null;

        foreach ($daily as $index => $row) {
            // A day with no postings on $on itself still closes at the opening balance.
            if ($index === 0 && substr((string) $row->entry_date, 0, 10) !== $day) {
                $lowest = $running;
            }

            $running += (int) $row->delta;
            $lowest = $lowest === null ? $running : min($lowest, $running);
        }

        return Money::ofPoisha($lowest ?? $running);
    }
}
