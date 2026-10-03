<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Support;

use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Enums\Role;
use App\Filament\Support\Display;

/**
 * Human-readable summaries of rate plans for tables, confirmations and pages.
 */
final class RatePlanPresenter
{
    public static function lateFee(RatePlanData $data): string
    {
        $frequency = $data->lateFeeFrequency?->getLabel() ?? '';

        return match ($data->lateFeeMode) {
            LateFeeMode::None => __('rates.plan.no_late_fee'),
            LateFeeMode::Fixed => __('rates.plan.late_fee_fixed_summary', [
                'amount' => Display::money($data->lateFeeFixed),
                'frequency' => $frequency,
            ]),
            LateFeeMode::Percent => __('rates.plan.late_fee_percent_summary', [
                'rate' => $data->lateFeeRate?->format(app()->getLocale()) ?? '',
                'base' => $data->lateFeeBase?->getLabel() ?? '',
                'frequency' => $frequency,
            ]).($data->lateFeeCap === null ? '' : ' ('.__('rates.plan.late_fee_cap_summary', ['cap' => Display::money($data->lateFeeCap)]).')'),
        };
    }

    public static function allocationOrder(RatePlanData $data): string
    {
        return implode(' → ', array_map(
            fn (string $type): string => (string) __('rates.due_type.'.$type),
            $data->allocationOrder,
        ));
    }

    /**
     * "Waiting for President, Secretary" while pending; empty otherwise.
     */
    public static function pendingRoles(RatePlan $plan): string
    {
        if ($plan->status !== RatePlanStatus::PendingApproval) {
            return '';
        }

        $approved = array_map(fn (Role $role): string => $role->value, $plan->approvedRoles());
        $missing = array_filter(ApproveRatePlan::REQUIRED_ROLES, fn (Role $role): bool => ! in_array($role->value, $approved, true));

        return __('rates.plan.pending_roles', [
            'roles' => implode(', ', array_map(fn (Role $role): string => $role->getLabel(), $missing)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'effective_from' => __('rates.plan.effective_from'),
            'is_retroactive' => __('rates.plan.is_retroactive'),
            'share_unit' => __('rates.plan.share_unit'),
            'service_charge' => __('rates.plan.service_charge'),
            'registration_fee' => __('rates.plan.registration_fee'),
            'due_day' => __('rates.plan.due_day'),
            'grace_days' => __('rates.plan.grace_days'),
            'late_fee' => __('rates.plan.late_fee'),
            'advance_policy' => __('rates.plan.advance_policy'),
            'registration_fee_on_rate_increase' => __('rates.plan.registration_fee_on_rate_increase'),
            'allocation_order' => __('rates.plan.allocation_order'),
            'notes' => __('rates.plan.notes'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summaryValues(?RatePlanData $data): array
    {
        if ($data === null) {
            return [];
        }

        return [
            'effective_from' => $data->effectiveFrom,
            'is_retroactive' => $data->isRetroactive,
            'share_unit' => $data->shareUnit,
            'service_charge' => $data->serviceChargePerShare,
            'registration_fee' => $data->registrationFeePerShare,
            'due_day' => Display::digits($data->dueDay),
            'grace_days' => Display::digits($data->graceDays),
            'late_fee' => self::lateFee($data),
            'advance_policy' => $data->advancePolicy,
            'registration_fee_on_rate_increase' => $data->registrationFeeOnRateIncrease,
            'allocation_order' => self::allocationOrder($data),
            'notes' => $data->notes,
        ];
    }

    /**
     * Parses raw form state for a confirmation summary, or null if it is not valid yet.
     *
     * @param  array<string, mixed>|null  $state
     */
    public static function dataFromState(?array $state): ?RatePlanData
    {
        try {
            return RatePlanData::fromForm($state ?? []);
        } catch (\Throwable) {
            return null;
        }
    }
}
