<?php

declare(strict_types=1);

namespace App\Domain\Exits\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Contracts\SubledgerSource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GL 2301 must equal the approved exit settlements not yet paid out (§6.7 invariant 2).
 */
final class ExitSubledger implements SubledgerSource
{
    public function accountCode(): string
    {
        return AccountCode::EXIT_PAYABLE;
    }

    public function label(): string
    {
        return __('exits.register');
    }

    public function balanceAsOf(CarbonImmutable $date): Money
    {
        $day = $date->toDateString();

        $approved = (int) DB::table('member_exits as x')
            ->join('journal_entries as e', 'e.id', '=', 'x.settlement_journal_entry_id')
            ->where('e.entry_date', '<=', $day)
            ->sum('x.net_poisha');

        $paid = (int) DB::table('member_exits as x')
            ->join('journal_entries as e', 'e.id', '=', 'x.payout_journal_entry_id')
            ->where('e.entry_date', '<=', $day)
            ->sum('x.net_poisha');

        return Money::ofPoisha(-($approved - $paid));
    }
}
