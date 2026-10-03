<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cash, bank and wallets cannot go below zero: money that is not there cannot be paid out.
 * Takes a transaction-level advisory lock per account, so two approvals cannot both spend the
 * same balance. Call inside the transaction that posts the outflow.
 */
final class FundsGuard
{
    public function assertCovers(Account $account, Money $outflow): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7101, $account->id]);

        $balance = Money::ofPoisha((int) DB::table('journal_lines')
            ->where('account_id', $account->id)
            ->sum(DB::raw('debit_poisha - credit_poisha')));

        if ($balance->isLessThan($outflow)) {
            throw DomainRuleViolation::because('expenses.errors.insufficient_funds', [
                'account' => $account->displayName(),
                'balance' => $balance->format(app()->getLocale()),
                'amount' => $outflow->format(app()->getLocale()),
            ]);
        }
    }
}
