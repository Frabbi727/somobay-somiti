<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DividendStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Credited = 'credited';

    public function getLabel(): string
    {
        return __('year_end.dividend_status.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Unpaid ? 'warning' : 'success';
    }
}
