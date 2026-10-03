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
    case Deposit = 'deposit';

    /**
     * @return list<string>
     */
    public static function defaultAllocationOrder(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
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
            self::Deposit => 'success',
        };
    }
}
