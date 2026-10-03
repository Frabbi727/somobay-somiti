<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Reports;

use App\Domain\Settings\Models\RatePlan;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * What generating a month creates (or created). The same object is used for the preview and
 * the result, so the two can be compared exactly.
 */
final readonly class DueGenerationPlan
{
    public function __construct(
        public YearMonth $month,
        public RatePlan $plan,
        public int $memberCount,
        public int $shareCount,
        public int $depositCount,
        public Money $depositTotal,
        public int $serviceCount,
        public Money $serviceTotal,
        public int $alreadyExisting,
    ) {}

    public function newCount(): int
    {
        return $this->depositCount + $this->serviceCount;
    }

    public function newTotal(): Money
    {
        return $this->depositTotal->plus($this->serviceTotal);
    }
}
