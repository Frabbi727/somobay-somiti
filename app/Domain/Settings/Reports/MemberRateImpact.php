<?php

declare(strict_types=1);

namespace App\Domain\Settings\Reports;

use App\Support\Money\Money;

/**
 * One member's monthly amount under the old and new plan, and what their advance covers.
 */
final readonly class MemberRateImpact
{
    public function __construct(
        public int $memberId,
        public string $memberNo,
        public string $name,
        public int $shares,
        public Money $oldMonthly,
        public Money $newMonthly,
        public Money $advance,
    ) {}

    /**
     * Whole months the advance covers at a monthly amount (0 if nothing is due monthly).
     */
    public static function months(Money $advance, Money $monthly): int
    {
        return $monthly->isPositive() ? intdiv($advance->poisha, $monthly->poisha) : 0;
    }

    public function coverageOld(): int
    {
        return self::months($this->advance, $this->oldMonthly);
    }

    public function coverageNew(): int
    {
        return self::months($this->advance, $this->newMonthly);
    }

    /**
     * Extra money needed for the advance to cover as many months at the new rate as at the old.
     */
    public function shortfall(): Money
    {
        return $this->newMonthly->multipliedByInt($this->coverageOld())->minus($this->advance)->max(Money::zero());
    }
}
