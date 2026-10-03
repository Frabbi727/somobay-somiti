<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Contributions\Contracts\AdvanceBalances;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Advance balances read from member-tagged postings on 2111 until the advance ledger (Phase 5) exists.
 */
final class LedgerAdvanceBalances implements AdvanceBalances
{
    public function all(): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', AccountCode::MEMBER_ADVANCE)
            ->whereNotNull('l.member_id')
            ->groupBy('l.member_id')
            ->havingRaw('SUM(l.credit_poisha) - SUM(l.debit_poisha) > 0')
            ->selectRaw('l.member_id, (SUM(l.credit_poisha) - SUM(l.debit_poisha))::bigint AS balance')
            ->pluck('balance', 'member_id');

        $balances = [];

        foreach ($rows as $memberId => $balance) {
            $balances[(int) $memberId] = Money::ofPoisha((int) $balance);
        }

        return $balances;
    }
}
