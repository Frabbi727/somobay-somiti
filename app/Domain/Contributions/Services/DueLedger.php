<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Contracts\RatePlanUsage;
use App\Domain\Settings\Models\RatePlan;
use App\Support\Time\YearMonth;

/**
 * What the dues table tells rate-plan rules: which months have monthly dues (BR-7) and
 * whether a plan is used by any due (BR-6).
 */
final class DueLedger implements GeneratedMonths, RatePlanUsage
{
    public function latest(): ?YearMonth
    {
        $month = Due::query()->where('type', DueType::Deposit)->max('month');

        return $month === null ? null : YearMonth::parse(substr((string) $month, 0, 10));
    }

    public function isReferenced(RatePlan $plan): bool
    {
        return Due::query()->where('rate_plan_id', $plan->id)->exists();
    }
}
