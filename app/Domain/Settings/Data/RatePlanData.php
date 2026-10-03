<?php

declare(strict_types=1);

namespace App\Domain\Settings\Data;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Settings\Enums\AdvancePolicy;
use App\Domain\Settings\Enums\LateFeeBase;
use App\Domain\Settings\Enums\LateFeeFrequency;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RegistrationFeePolicy;
use App\Domain\Settings\Models\RatePlan;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use BackedEnum;

final readonly class RatePlanData
{
    /**
     * @param  list<string>  $allocationOrder
     */
    public function __construct(
        public YearMonth $effectiveFrom,
        public Money $shareUnit,
        public Money $serviceChargePerShare,
        public Money $registrationFeePerShare,
        public int $dueDay,
        public int $graceDays,
        public LateFeeMode $lateFeeMode = LateFeeMode::None,
        public ?Money $lateFeeFixed = null,
        public ?Bps $lateFeeRate = null,
        public ?LateFeeBase $lateFeeBase = null,
        public ?Money $lateFeeCap = null,
        public ?LateFeeFrequency $lateFeeFrequency = null,
        public AdvancePolicy $advancePolicy = AdvancePolicy::ApplyAtCurrentRate,
        public RegistrationFeePolicy $registrationFeeOnRateIncrease = RegistrationFeePolicy::None,
        public array $allocationOrder = [],
        public bool $isRetroactive = false,
        public ?string $notes = null,
    ) {}

    /**
     * Build from Filament form state: money fields arrive as Money, the percentage as text.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $mode = self::enum(LateFeeMode::class, $data['late_fee_mode'] ?? null) ?? LateFeeMode::None;
        $percent = $data['late_fee_percent'] ?? null;
        $order = $data['allocation_order'] ?? null;
        $notes = $data['notes'] ?? null;

        return new self(
            effectiveFrom: YearMonth::parse((string) ($data['effective_from'] ?? '')),
            shareUnit: self::money($data['share_unit_poisha'] ?? null) ?? Money::zero(),
            serviceChargePerShare: self::money($data['service_charge_per_share_poisha'] ?? null) ?? Money::zero(),
            registrationFeePerShare: self::money($data['registration_fee_per_share_poisha'] ?? null) ?? Money::zero(),
            dueDay: (int) ($data['due_day'] ?? 0),
            graceDays: (int) ($data['grace_days'] ?? 0),
            lateFeeMode: $mode,
            lateFeeFixed: $mode === LateFeeMode::Fixed ? self::money($data['late_fee_fixed_poisha'] ?? null) : null,
            lateFeeRate: $mode === LateFeeMode::Percent && is_string($percent) && trim($percent) !== '' ? Bps::ofPercent($percent) : null,
            lateFeeBase: $mode === LateFeeMode::Percent ? self::enum(LateFeeBase::class, $data['late_fee_base'] ?? null) : null,
            lateFeeCap: $mode === LateFeeMode::Percent ? self::money($data['late_fee_cap_poisha'] ?? null) : null,
            lateFeeFrequency: $mode === LateFeeMode::None ? null : self::enum(LateFeeFrequency::class, $data['late_fee_frequency'] ?? null),
            advancePolicy: self::enum(AdvancePolicy::class, $data['advance_policy'] ?? null) ?? AdvancePolicy::ApplyAtCurrentRate,
            registrationFeeOnRateIncrease: self::enum(RegistrationFeePolicy::class, $data['registration_fee_on_rate_increase'] ?? null) ?? RegistrationFeePolicy::None,
            allocationOrder: is_array($order)
                ? array_values(array_map(fn (mixed $type): string => $type instanceof DueType ? $type->value : (string) $type, $order))
                : DueType::defaultAllocationOrder(),
            isRetroactive: (bool) ($data['is_retroactive'] ?? false),
            notes: is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
        );
    }

    public static function fromPlan(RatePlan $plan): self
    {
        return new self(
            effectiveFrom: $plan->effective_from,
            shareUnit: $plan->share_unit_poisha,
            serviceChargePerShare: $plan->service_charge_per_share_poisha,
            registrationFeePerShare: $plan->registration_fee_per_share_poisha,
            dueDay: $plan->due_day,
            graceDays: $plan->grace_days,
            lateFeeMode: $plan->late_fee_mode,
            lateFeeFixed: $plan->late_fee_fixed_poisha,
            lateFeeRate: $plan->lateFeeBps(),
            lateFeeBase: $plan->late_fee_base,
            lateFeeCap: $plan->late_fee_cap_poisha,
            lateFeeFrequency: $plan->late_fee_frequency,
            advancePolicy: $plan->advance_policy,
            registrationFeeOnRateIncrease: $plan->registration_fee_on_rate_increase,
            allocationOrder: $plan->allocation_order,
            isRetroactive: $plan->is_retroactive,
            notes: $plan->notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'effective_from' => $this->effectiveFrom,
            'is_retroactive' => $this->isRetroactive,
            'share_unit_poisha' => $this->shareUnit,
            'service_charge_per_share_poisha' => $this->serviceChargePerShare,
            'registration_fee_per_share_poisha' => $this->registrationFeePerShare,
            'due_day' => $this->dueDay,
            'grace_days' => $this->graceDays,
            'late_fee_mode' => $this->lateFeeMode,
            'late_fee_fixed_poisha' => $this->lateFeeFixed,
            'late_fee_bps' => $this->lateFeeRate?->value,
            'late_fee_base' => $this->lateFeeBase,
            'late_fee_cap_poisha' => $this->lateFeeCap,
            'late_fee_frequency' => $this->lateFeeFrequency,
            'advance_policy' => $this->advancePolicy,
            'registration_fee_on_rate_increase' => $this->registrationFeeOnRateIncrease,
            'allocation_order' => $this->allocationOrder,
            'notes' => $this->notes,
        ];
    }

    private static function money(mixed $value): ?Money
    {
        return match (true) {
            $value instanceof Money => $value,
            is_string($value) && trim($value) !== '' => Money::ofTaka($value),
            default => null,
        };
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): ?BackedEnum
    {
        return match (true) {
            $value instanceof $enum => $value,
            is_string($value) && $value !== '' => $enum::tryFrom($value),
            default => null,
        };
    }
}
