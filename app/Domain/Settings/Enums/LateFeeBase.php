<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum LateFeeBase: string implements HasLabel
{
    case DepositOnly = 'deposit_only';
    case DepositPlusService = 'deposit_plus_service';
    case OutstandingTotal = 'outstanding_total';

    public function getLabel(): string
    {
        return __('rates.late_fee_base.'.$this->value);
    }
}
