<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Contracts\SubledgerSource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GL 2111 must equal the sum of member advance balances (§6.7 invariant 2). Balances are
 * credit-side, so the debit-positive figure is negated.
 */
final class AdvanceSubledger implements SubledgerSource
{
    public function accountCode(): string
    {
        return AccountCode::MEMBER_ADVANCE;
    }

    public function label(): string
    {
        return __('payments.advance_ledger');
    }

    public function balanceAsOf(CarbonImmutable $date): Money
    {
        $total = DB::table('advance_ledger_entries as a')
            ->join('journal_entries as e', 'e.id', '=', 'a.journal_entry_id')
            ->where('e.entry_date', '<=', $date->toDateString())
            ->sum('a.delta_poisha');

        return Money::ofPoisha(-(int) $total);
    }
}
