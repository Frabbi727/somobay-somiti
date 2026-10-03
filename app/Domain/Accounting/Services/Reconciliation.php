<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Contracts\SubledgerSource;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Reports\ControlCheck;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compares each control account with its sub-ledger (§6.7 invariant 2).
 *
 * By default the sub-ledger of a member control account is the sum of its member-tagged
 * journal lines, which catches postings that slipped in without a member. Modules with their
 * own registers (advance ledger, dividend register, exits) register a SubledgerSource.
 */
final class Reconciliation
{
    /**
     * @param  iterable<SubledgerSource>  $sources
     */
    public function __construct(private readonly iterable $sources = []) {}

    /**
     * @return list<ControlCheck>
     */
    public function controlVsSubledger(CarbonImmutable $asOf): array
    {
        $sources = [];

        foreach ($this->sources as $source) {
            $sources[$source->accountCode()] = $source;
        }

        $checks = [];

        foreach (Account::query()->where('is_control', true)->orderBy('code')->get() as $account) {
            $generalLedger = $this->net($account, $asOf, memberTaggedOnly: false);
            $source = $sources[$account->code] ?? null;

            $checks[] = new ControlCheck(
                account: $account,
                source: $source?->label() ?? __('reports.reconciliation.member_lines'),
                generalLedger: $generalLedger,
                subledger: $source?->balanceAsOf($asOf) ?? $this->net($account, $asOf, memberTaggedOnly: true),
            );
        }

        return $checks;
    }

    private function net(Account $account, CarbonImmutable $asOf, bool $memberTaggedOnly): Money
    {
        $net = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id)
            ->where('e.entry_date', '<=', $asOf->toDateString())
            ->when($memberTaggedOnly, fn ($query) => $query->whereNotNull('l.member_id'))
            ->selectRaw('COALESCE(SUM(l.debit_poisha - l.credit_poisha), 0)::bigint AS net')
            ->value('net');

        return Money::ofPoisha((int) $net);
    }
}
