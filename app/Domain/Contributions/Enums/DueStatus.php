<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DueStatus: string implements HasColor, HasLabel
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
            self::Cancelled, self::Waived => 'gray',
        };
    }
}
