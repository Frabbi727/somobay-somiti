<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The state of one month of a member's monthly charges (deposit + service charge), as shown on
 * the member's month strip.
 */
enum MonthState: string implements HasColor, HasIcon, HasLabel
{
    case Paid = 'paid';
    case PartlyPaid = 'partly_paid';
    case Unpaid = 'unpaid';
    case NotDueYet = 'not_due_yet';
    case Waived = 'waived';
    case Cancelled = 'cancelled';
    case BeforeStart = 'before_start';
    case NotBilled = 'not_billed';

    public function getLabel(): string
    {
        return __('portal.month_state.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::PartlyPaid, self::NotDueYet => 'warning',
            self::Unpaid => 'danger',
            self::Waived => 'info',
            self::Cancelled, self::BeforeStart, self::NotBilled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Paid => Heroicon::OutlinedCheckCircle,
            self::PartlyPaid => Heroicon::OutlinedAdjustmentsHorizontal,
            self::Unpaid => Heroicon::OutlinedExclamationCircle,
            self::NotDueYet => Heroicon::OutlinedClock,
            self::Waived => Heroicon::OutlinedGift,
            self::Cancelled => Heroicon::OutlinedXCircle,
            self::BeforeStart, self::NotBilled => Heroicon::OutlinedMinusCircle,
        };
    }
}
