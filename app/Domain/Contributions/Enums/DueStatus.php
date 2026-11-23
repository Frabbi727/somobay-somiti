<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum DueStatus: string implements HasColor, HasIcon, HasLabel
{
    case Open = 'open';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
    case Waived = 'waived';

    public function getLabel(): string
    {
        return __('dues.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Settled => 'success',
            self::Waived => 'info',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Open => Heroicon::OutlinedClock,
            self::Settled => Heroicon::OutlinedCheckCircle,
            self::Waived => Heroicon::OutlinedGift,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }
}
