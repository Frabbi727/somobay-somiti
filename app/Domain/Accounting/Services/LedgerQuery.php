<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Reports\LedgerReport;
use App\Domain\Accounting\Reports\LedgerRow;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * An account (or one member's slice of it) line by line, with an opening balance and a
 * running balance computed by a SQL window sum over integer poisha.
 */
final class LedgerQuery
{
    public function forAccount(Account $account, CarbonImmutable $from, CarbonImmutable $until, ?int $memberId = null): LedgerReport
    {
        $opening = (int) $this->lines($account, $memberId)
            ->where('e.entry_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit_poisha - l.credit_poisha), 0)::bigint AS net')
            ->value('net');

        $records = $this->lines($account, $memberId)
            ->whereBetween('e.entry_date', [$from->toDateString(), $until->toDateString()])
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('l.line_no')
            ->selectRaw(<<<'SQL'
                e.id AS entry_id, e.entry_date, e.voucher_no, e.narration,
                l.member_id, l.memo, l.debit_poisha, l.credit_poisha,
                SUM(l.debit_poisha - l.credit_poisha) OVER (
                    ORDER BY e.entry_date, e.id, l.line_no ROWS UNBOUNDED PRECEDING
                )::bigint AS running
                SQL)
            ->get();

        $rows = [];

        foreach ($records as $record) {
            $rows[] = new LedgerRow(
                date: CarbonImmutable::parse((string) $record->entry_date),
                entryId: (int) $record->entry_id,
                voucherNo: (string) $record->voucher_no,
                narration: (string) $record->narration,
                memberId: $record->member_id === null ? null : (int) $record->member_id,
                memo: $record->memo === null ? null : (string) $record->memo,
                debit: Money::ofPoisha((int) $record->debit_poisha),
                credit: Money::ofPoisha((int) $record->credit_poisha),
                balance: Money::ofPoisha($opening)->plus(Money::ofPoisha((int) $record->running)),
            );
        }

        return new LedgerReport($account, $from, $until, $memberId, Money::ofPoisha($opening), $rows);
    }

    private function lines(Account $account, ?int $memberId): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id)
            ->when($memberId !== null, fn (Builder $query): Builder => $query->where('l.member_id', $memberId));
    }
}
