<?php

declare(strict_types=1);

namespace App\Domain\Settings\Contracts;

use App\Domain\Settings\Models\RatePlan;

/**
 * Whether any due was built from a plan (BR-6: a plan may be cancelled only while unused).
 * The dues engine (Phase 4) binds the real implementation.
 */
interface RatePlanUsage
{
    public function isReferenced(RatePlan $plan): bool;
}
