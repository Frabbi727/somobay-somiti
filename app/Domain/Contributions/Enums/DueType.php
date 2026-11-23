<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kinds of member dues. The order of defaultAllocationOrder() is the order in which a payment
 * settles dues inside one month (BR-13).
 */
enum DueType: string implements HasColor, HasLabel
{
    case LateFee = 'late_fee';
    case ServiceCharge = 'service_charge';
    case Registration = 'registration';
    case EntryPremium = 'entry_premium';
    case Deposit = 'deposit';

    /**
     * @return list<string>
     */
    public static function defaultAllocationOrder(): array
    {
        // The entry premium is a one-off charge at joining; it always settles right after the
        // registration fee and is not part of the order a rate plan lets staff change.
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type !== self::EntryPremium),
        ));
    }

    public function getLabel(): string
    {
        return __('rates.due_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LateFee => 'danger',
            self::ServiceCharge => 'warning',
            self::Registration => 'info',
            self::EntryPremium => 'primary',
            self::Deposit => 'success',
        };
    }

    /**
     * The monthly charges that decide "paid through"; everything else is shown as "other amounts
     * owed".
     */
    public function isMonthly(): bool
    {
        return $this === self::Deposit || $this === self::ServiceCharge;
    }
}
