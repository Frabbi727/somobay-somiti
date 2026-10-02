<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NormalBalance: string implements HasColor, HasLabel
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return __('accounting.normal_balance.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Debit ? 'info' : 'warning';
    }
}
