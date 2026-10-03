<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum LateFeeMode: string implements HasLabel
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percent = 'percent';

    public function getLabel(): string
    {
        return __('rates.late_fee_mode.'.$this->value);
    }
}
