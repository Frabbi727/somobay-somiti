<?php

declare(strict_types=1);

namespace App\Domain\Investments\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum InvestmentStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return __('investments.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Rejected => 'danger',
            self::Cancelled, self::Closed => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Active => Heroicon::OutlinedArrowTrendingUp,
            self::Rejected => Heroicon::OutlinedNoSymbol,
            self::Cancelled => Heroicon::OutlinedXCircle,
            self::Closed => Heroicon::OutlinedLockClosed,
        };
    }
}
