<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Models\Account;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Receipts & payments account: money in and out of cash, bank and wallets for a period, by the
 * account on the other side. Transfers between cash-like accounts (contra) are left out.
 */
final class CashMovements
{
    /**
     * @var list<string>
     */
    public const array CASH_CODES = [AccountCode::CASH, AccountCode::BANK, AccountCode::BKASH, AccountCode::NAGAD];

    /**
     * @return array{opening: Money, receipts: list<array{account: Account, amount: Money}>, payments: list<array{account: Account, amount: Money}>, total_receipts: Money, total_payments: Money, closing: Money}
     */
    public function receiptsAndPayments(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $cashIds = array_values(array_map(fn (mixed $id): int => (int) $id, Account::query()->whereIn('code', self::CASH_CODES)->pluck('id')->all()));

        $opening = $this->cashBalance($cashIds, $from->subDay());
        $closing = $this->cashBalance($cashIds, $until);

        // Entries in the period that touch cash, with the cash side's net.
        $entries = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereBetween('e.entry_date', [$from->toDateString(), $until->toDateString()])
            ->whereIn('l.account_id', $cashIds)
            ->groupBy('l.journal_entry_id')
            ->selectRaw('l.journal_entry_id, (SUM(l.debit_poisha) - SUM(l.credit_poisha))::bigint AS cash_net')
            ->pluck('cash_net', 'journal_entry_id');

        $receiptIds = $entries->filter(fn ($net): bool => (int) $net > 0)->keys()->all();
        $paymentIds = $entries->filter(fn ($net): bool => (int) $net < 0)->keys()->all();

        $receipts = $this->otherSide($receiptIds, $cashIds, receipts: true);
        $payments = $this->otherSide($paymentIds, $cashIds, receipts: false);

        return [
            'opening' => $opening,
            'receipts' => $receipts,
            'payments' => $payments,
            'total_receipts' => Money::sum(array_column($receipts, 'amount')),
            'total_payments' => Money::sum(array_column($payments, 'amount')),
            'closing' => $closing,
        ];
    }

    /**
     * @param  list<int>  $cashIds
     */
    private function cashBalance(array $cashIds, CarbonImmutable $asOf): Money
    {
        return Money::ofPoisha((int) DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $cashIds)
            ->where('e.entry_date', '<=', $asOf->toDateString())
            ->sum(DB::raw('l.debit_poisha - l.credit_poisha')));
    }

    /**
     * Net of the non-cash lines of the given entries, per account.
     *
     * @param  array<int, int|string>  $entryIds
     * @param  list<int>  $cashIds
     * @return list<array{account: Account, amount: Money}>
     */
    private function otherSide(array $entryIds, array $cashIds, bool $receipts): array
    {
        if ($entryIds === []) {
            return [];
        }

        $nets = DB::table('journal_lines')
            ->whereIn('journal_entry_id', $entryIds)
            ->whereNotIn('account_id', $cashIds)
            ->groupBy('account_id')
            ->selectRaw($receipts
                ? 'account_id, (SUM(credit_poisha) - SUM(debit_poisha))::bigint AS amount'
                : 'account_id, (SUM(debit_poisha) - SUM(credit_poisha))::bigint AS amount')
            ->pluck('amount', 'account_id');

        $rows = [];

        foreach (Account::withTrashed()->whereIn('id', $nets->keys()->all())->orderBy('code')->get() as $account) {
            $amount = Money::ofPoisha((int) $nets->get($account->id));

            if (! $amount->isZero()) {
                $rows[] = ['account' => $account, 'amount' => $amount];
            }
        }

        return $rows;
    }
}
