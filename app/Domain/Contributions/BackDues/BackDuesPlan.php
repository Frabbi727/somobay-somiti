<?php

declare(strict_types=1);

namespace App\Domain\Contributions\BackDues;

use App\Domain\Settings\Models\RatePlan;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * What a late joiner is billed for the months before their shares start (design 2026-11-22 §2).
 * The same object drives the preview and the rows written, so the two can never differ.
 */
final readonly class BackDuesPlan
{
    /**
     * @param  list<array{month: YearMonth, plan: RatePlan, deposit: Money, serviceCharge: Money}>  $months  oldest first
     */
    public function __construct(
        public YearMonth $duesStart,
        public YearMonth $shareStart,
        public int $shares,
        public array $months,
        public Money $registrationFee,
        public Bps $premiumRate,
        public Money $premium,
        /** The rate plan the entry premium due is stamped with (the joining month's). */
        public ?RatePlan $premiumPlan = null,
    ) {}

    public function hasBackMonths(): bool
    {
        return $this->months !== [];
    }

    public function deposits(): Money
    {
        return Money::sum(array_map(fn (array $row): Money => $row['deposit'], $this->months));
    }

    public function serviceCharges(): Money
    {
        return Money::sum(array_map(fn (array $row): Money => $row['serviceCharge'], $this->months));
    }

    /**
     * Everything billed at joining: back months, the registration fee and the entry premium.
     */
    public function total(): Money
    {
        return $this->deposits()->plus($this->serviceCharges())->plus($this->registrationFee)->plus($this->premium);
    }

    /**
     * @return array<string, int|string>
     */
    public function toLog(): array
    {
        return [
            'dues_start' => (string) $this->duesStart,
            'share_start' => (string) $this->shareStart,
            'months' => count($this->months),
            'deposits_poisha' => $this->deposits()->poisha,
            'service_charges_poisha' => $this->serviceCharges()->poisha,
            'registration_poisha' => $this->registrationFee->poisha,
            'premium_bps' => $this->premiumRate->value,
            'premium_poisha' => $this->premium->poisha,
            'total_poisha' => $this->total()->poisha,
        ];
    }
}
