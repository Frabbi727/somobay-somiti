<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Contracts\RatePlanUsage;
use App\Domain\Settings\Models\RatePlan;
use App\Support\Time\YearMonth;

/**
 * Stand-in until the dues engine exists: nothing has been generated and no plan is in use.
 */
final class NoDuesYet implements GeneratedMonths, RatePlanUsage
{
    public function latest(): ?YearMonth
    {
        return null;
    }

    public function isReferenced(RatePlan $plan): bool
    {
        return false;
    }
}
