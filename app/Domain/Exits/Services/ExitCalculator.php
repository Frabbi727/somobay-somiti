<?php

declare(strict_types=1);

namespace App\Domain\Exits\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Exits\Data\ExitPreview;
use App\Domain\Members\Models\Member;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\DB;

/**
 * What a member would receive if their exit were approved now (BR-22).
 */
final class ExitCalculator
{
    public function __construct(private readonly AdvanceLedger $advances) {}

    public function preview(Member $member, YearMonth $exitMonth, Money $exitFee): ExitPreview
    {
        return new ExitPreview(
            savings: $this->memberBalance($member->id, AccountCode::MEMBER_SAVINGS),
            advance: $this->advances->balance($member->id),
            dividends: Money::ofPoisha((int) DB::table('dividend_lines')->where('member_id', $member->id)->where('status', DividendStatus::Unpaid->value)->sum('amount_poisha')),
            releasedFees: Money::ofPoisha((int) DB::table('dues')
                ->where('member_id', $member->id)
                ->where('month', '>', $exitMonth->toDateString())
                ->where('type', '!=', DueType::Deposit->value)
                ->whereIn('status', [DueStatus::Open->value, DueStatus::Settled->value])
                ->sum('paid_poisha')),
            receivables: $this->receivables($member->id, $exitMonth),
            exitFee: $exitFee,
        );
    }

    /**
     * Unpaid service charges, registration and late fees up to the exit month (unpaid deposits are
     * savings never made, not debts).
     */
    public function receivables(int $memberId, YearMonth $exitMonth): Money
    {
        return Money::ofPoisha((int) DB::table('dues')
            ->where('member_id', $memberId)
            ->where('month', '<=', $exitMonth->toDateString())
            ->where('type', '!=', DueType::Deposit->value)
            ->where('status', DueStatus::Open->value)
            ->sum('outstanding_poisha'));
    }

    /**
     * Credit balance of a member-tagged liability account.
     */
    public function memberBalance(int $memberId, string $code): Money
    {
        return Money::ofPoisha((int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->where('l.member_id', $memberId)
            ->sum(DB::raw('l.credit_poisha - l.debit_poisha')));
    }
}
