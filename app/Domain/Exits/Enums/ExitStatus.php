<?php

declare(strict_types=1);

namespace App\Domain\Exits\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExitStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('exits.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }
}
