<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Models\RatePlan;
use App\Support\Time\YearMonth;

/**
 * BR-5: the plan for a month is the approved plan with the latest effective_from ≤ that month.
 */
final class RateResolver
{
    /**
     * @throws NoRatePlanForMonth
     */
    public function for(YearMonth $month): RatePlan
    {
        return $this->find($month) ?? throw NoRatePlanForMonth::for($month);
    }

    public function find(YearMonth $month): ?RatePlan
    {
        return RatePlan::query()
            ->where('status', RatePlanStatus::Approved)
            ->where('effective_from', '<=', $month->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }
}
