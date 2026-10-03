<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

final class RatePlanRules
{
    public const int MAX_GRACE_DAYS = 60;

    public function __construct(private readonly GeneratedMonths $generated) {}

    public function assertValid(RatePlanData $data): void
    {
        if (! $data->shareUnit->isPositive()) {
            throw DomainRuleViolation::because('rates.errors.share_unit_positive');
        }

        if ($data->serviceChargePerShare->isNegative() || $data->registrationFeePerShare->isNegative()) {
            throw DomainRuleViolation::because('rates.errors.negative_amount');
        }

        if ($data->dueDay < 1 || $data->dueDay > 28) {
            throw DomainRuleViolation::because('rates.errors.due_day');
        }

        if ($data->graceDays < 0 || $data->graceDays > self::MAX_GRACE_DAYS) {
            throw DomainRuleViolation::because('rates.errors.grace_days', ['max' => self::MAX_GRACE_DAYS]);
        }

        $this->assertLateFee($data);

        $order = $data->allocationOrder;
        $expected = DueType::defaultAllocationOrder();
        sort($order);
        sort($expected);

        if ($order !== $expected) {
            throw DomainRuleViolation::because('rates.errors.allocation_order');
        }
    }

    /**
     * BR-7: a plan may not start in a month that already has dues unless it is a
     * retroactive correction (which the dues engine turns into adjustment dues).
     */
    public function assertMonthOpen(RatePlan $plan): void
    {
        $latest = $this->generated->latest();

        if ($latest !== null && $plan->effective_from->isSameOrBefore($latest) && ! $plan->is_retroactive) {
            throw DomainRuleViolation::because('rates.errors.month_generated', [
                'month' => (string) $plan->effective_from,
                'latest' => (string) $latest,
            ]);
        }
    }

    private function assertLateFee(RatePlanData $data): void
    {
        $valid = match ($data->lateFeeMode) {
            LateFeeMode::None => true,
            LateFeeMode::Fixed => $data->lateFeeFixed?->isPositive() === true && $data->lateFeeFrequency !== null,
            LateFeeMode::Percent => $data->lateFeeRate !== null && ! $data->lateFeeRate->isZero()
                && $data->lateFeeBase !== null && $data->lateFeeFrequency !== null
                && ($data->lateFeeCap === null || $data->lateFeeCap->isPositive()),
        };

        if (! $valid) {
            throw DomainRuleViolation::because('rates.errors.late_fee_incomplete', ['mode' => $data->lateFeeMode->getLabel()]);
        }
    }
}
