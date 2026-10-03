<?php

declare(strict_types=1);

namespace App\Domain\Settings\Reports;

use App\Domain\Settings\Models\RatePlan;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * What approving a rate plan would change (SOMITI_SPEC.md §5.3). Amounts are monthly deposit
 * plus service charge, for active members, as of the plan's first month.
 */
final readonly class RateImpactPreview
{
    /**
     * @param  list<MemberRateImpact>  $members
     * @param  list<array{key: string, params: array<string, string|int>}>  $warnings
     */
    public function __construct(
        public RatePlan $plan,
        public ?RatePlan $previous,
        public YearMonth $from,
        public ?YearMonth $until,
        public array $members,
        public int $lockedDueCount,
        public Money $lockedDueAmount,
        public int $topUpLotCount,
        public Money $topUpAmount,
        public array $warnings,
    ) {}

    public function memberCount(): int
    {
        return count($this->members);
    }

    public function shareCount(): int
    {
        return array_sum(array_map(fn (MemberRateImpact $member): int => $member->shares, $this->members));
    }

    public function oldMonthlyTotal(): Money
    {
        return Money::sum(array_map(fn (MemberRateImpact $member): Money => $member->oldMonthly, $this->members));
    }

    public function newMonthlyTotal(): Money
    {
        return Money::sum(array_map(fn (MemberRateImpact $member): Money => $member->newMonthly, $this->members));
    }

    public function monthlyDifference(): Money
    {
        return $this->newMonthlyTotal()->minus($this->oldMonthlyTotal());
    }

    /**
     * @return list<MemberRateImpact>
     */
    public function membersWithAdvance(): array
    {
        return array_values(array_filter($this->members, fn (MemberRateImpact $member): bool => $member->advance->isPositive()));
    }

    public function totalShortfall(): Money
    {
        return Money::sum(array_map(fn (MemberRateImpact $member): Money => $member->shortfall(), $this->membersWithAdvance()));
    }
}
