<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Listeners;

use App\Domain\Contributions\Services\RegistrationFees;
use App\Domain\Settings\Events\RatePlanApproved;

final class ChargeRegistrationTopUps
{
    public function __construct(private readonly RegistrationFees $fees) {}

    public function handle(RatePlanApproved $event): void
    {
        $this->fees->topUpForPlan($event->plan);
    }
}
