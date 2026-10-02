<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PeriodStatus: string implements HasColor, HasIcon, HasLabel
{
    case Open = 'open';
    case Locked = 'locked';

    public function getLabel(): string
    {
        return __('accounting.period_status.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Open ? 'success' : 'gray';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::Open ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedLockClosed;
    }
}
