<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * One member's money position. Every member-facing figure (portal, mobile API, collection
 * screen, receipt, SMS) comes from here so the numbers never disagree.
 */
final readonly class MemberPositionData
{
    /**
     * @param  list<array{month: YearMonth, state: MonthState, owed: Money}>  $months  oldest first
     */
    public function __construct(
        /** Open dues whose due date has passed. */
        public Money $oweNow,
        /** Open dues billed but not due yet. */
        public Money $comingUp,
        /** Every open due (owe now + coming up). */
        public Money $outstanding,
        /** Open late fees, registration fees and entry premium (already inside the totals above). */
        public Money $otherOwed,
        /** Approved payments; reversed ones are no longer approved. */
        public Money $paidTotal,
        public Money $pendingTotal,
        public int $pendingCount,
        public Money $advance,
        public Money $savings,
        /** Latest month whose deposit and service charge (and every month before) are done. */
        public ?YearMonth $paidThrough,
        /** Deposit + service charge for the member's shares at the current rate. */
        public ?Money $monthlyAmount,
        /** Estimate: whole months of monthlyAmount the advance covers. */
        public int $advanceCoversMonths,
        public int $shares,
        public Money $billed,
        public Money $paidOnDues,
        public Money $waived,
        public Money $dueThisMonth,
        public Money $dueEarlier,
        public ?Money $monthlyDeposit,
        public array $months,
    ) {}
}
