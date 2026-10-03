<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum LateFeeFrequency: string implements HasLabel
{
    case Once = 'once';
    case MonthlyUntilPaid = 'monthly_until_paid';

    public function getLabel(): string
    {
        return __('rates.late_fee_frequency.'.$this->value);
    }
}
