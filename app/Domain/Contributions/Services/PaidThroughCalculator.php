<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Services\RateResolver;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * "Paid through" (§5.5) is calculated, never stored: the latest month M for which every due of
 * month ≤ M is settled (cancelled and waived dues don't count).
 */
final class PaidThroughCalculator
{
    public function __construct(
        private readonly AdvanceLedger $advances,
        private readonly RateResolver $rates,
    ) {}

    public function for(Member $member): ?YearMonth
    {
        $openMonth = Due::query()
            ->where('member_id', $member->id)
            ->where('status', DueStatus::Open)
            ->where('outstanding_poisha', '>', 0)
            ->min('month');

        if ($openMonth !== null) {
            $first = YearMonth::parse(substr((string) $openMonth, 0, 10));
            $settledBefore = Due::query()
                ->where('member_id', $member->id)
                ->where('type', DueType::Deposit)
                ->where('month', '<', $first->toDateString())
                ->exists();

            return $settledBefore ? $first->previous() : null;
        }

        $last = Due::query()->where('member_id', $member->id)->where('type', DueType::Deposit)->max('month');

        return $last === null ? null : YearMonth::parse(substr((string) $last, 0, 10));
    }

    /**
     * Rough count of further months the advance would cover at the rate of the month after
     * "paid through" (labelled as an estimate wherever shown).
     */
    public function estimatedMonths(Member $member): int
    {
        $advance = $this->advances->balance($member->id);
        $month = ($this->for($member) ?? YearMonth::current())->next();
        $plan = $this->rates->find($month);
        $shares = $member->sharesIn($month);

        if ($plan === null || $shares === 0 || ! $advance->isPositive()) {
            return 0;
        }

        $monthly = $plan->share_unit_poisha->plus($plan->service_charge_per_share_poisha)->multipliedByInt($shares);

        return $monthly->isPositive() ? intdiv($advance->poisha, $monthly->poisha) : 0;
    }

    public function advance(Member $member): Money
    {
        return $this->advances->balance($member->id);
    }
}
