<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Models\RatePlan;
use App\Models\User;
use App\Support\Time\YearMonth;

/**
 * "Duplicate as new version": copies a plan's rates into a new draft, by default starting
 * the month after the current month, ready to be adjusted.
 */
final class DuplicateRatePlan
{
    public function __construct(private readonly DraftRatePlan $draft) {}

    public function __invoke(User $actor, RatePlan $plan, ?YearMonth $effectiveFrom = null): RatePlan
    {
        $data = RatePlanData::fromPlan($plan);

        return ($this->draft)($actor, new RatePlanData(
            effectiveFrom: $effectiveFrom ?? YearMonth::current()->next(),
            shareUnit: $data->shareUnit,
            serviceChargePerShare: $data->serviceChargePerShare,
            registrationFeePerShare: $data->registrationFeePerShare,
            dueDay: $data->dueDay,
            graceDays: $data->graceDays,
            lateFeeMode: $data->lateFeeMode,
            lateFeeFixed: $data->lateFeeFixed,
            lateFeeRate: $data->lateFeeRate,
            lateFeeBase: $data->lateFeeBase,
            lateFeeCap: $data->lateFeeCap,
            lateFeeFrequency: $data->lateFeeFrequency,
            advancePolicy: $data->advancePolicy,
            registrationFeeOnRateIncrease: $data->registrationFeeOnRateIncrease,
            allocationOrder: $data->allocationOrder,
            isRetroactive: false,
            notes: null,
        ));
    }
}
