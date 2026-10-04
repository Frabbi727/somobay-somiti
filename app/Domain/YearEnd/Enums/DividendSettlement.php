<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Enums;

use Filament\Support\Contracts\HasLabel;

enum DividendSettlement: string implements HasLabel
{
    case Payout = 'payout';
    case Savings = 'savings';

    public function status(): DividendStatus
    {
        return $this === self::Payout ? DividendStatus::Paid : DividendStatus::Credited;
    }

    public function getLabel(): string
    {
        return __('year_end.settlement.'.$this->value);
    }
}
