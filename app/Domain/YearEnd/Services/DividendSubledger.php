<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Contracts\SubledgerSource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GL 2201 must equal the dividends declared but not yet paid or credited (§6.7 invariant 2).
 * Credit-side, so the debit-positive figure is negated.
 */
final class DividendSubledger implements SubledgerSource
{
    public function accountCode(): string
    {
        return AccountCode::DIVIDEND_PAYABLE;
    }

    public function label(): string
    {
        return __('year_end.dividend_register');
    }

    public function balanceAsOf(CarbonImmutable $date): Money
    {
        $day = $date->toDateString();

        $declared = (int) DB::table('dividend_lines as d')
            ->join('year_ends as y', 'y.id', '=', 'd.year_end_id')
            ->join('journal_entries as e', 'e.id', '=', 'y.appropriation_journal_entry_id')
            ->where('e.entry_date', '<=', $day)
            ->sum('d.amount_poisha');

        $settled = (int) DB::table('dividend_lines as d')
            ->join('journal_entries as e', 'e.id', '=', 'd.settlement_journal_entry_id')
            ->where('e.entry_date', '<=', $day)
            ->sum('d.amount_poisha');

        return Money::ofPoisha(-($declared - $settled));
    }
}
