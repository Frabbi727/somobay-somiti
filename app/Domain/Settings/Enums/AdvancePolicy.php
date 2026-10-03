<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum AdvancePolicy: string implements HasLabel
{
    case ApplyAtCurrentRate = 'apply_at_current_rate';
    case LockPrepaidMonths = 'lock_prepaid_months';

    public function getLabel(): string
    {
        return __('rates.advance_policy.'.$this->value);
    }
}
