<?php

declare(strict_types=1);

namespace App\Domain\Settings\Events;

use App\Domain\Settings\Models\RatePlan;

/**
 * Dispatched inside the approval transaction, so listeners' writes commit or roll back with it.
 */
final readonly class RatePlanApproved
{
    public function __construct(public RatePlan $plan) {}
}
