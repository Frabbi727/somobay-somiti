<?php

declare(strict_types=1);

namespace App\Domain\Members\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShareChangeType: string implements HasColor, HasLabel
{
    case Increase = 'increase';
    case Decrease = 'decrease';

    public function getLabel(): string
    {
        return __('members.share_change.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Increase ? 'success' : 'warning';
    }
}
