<?php

declare(strict_types=1);

namespace App\Domain\Investments\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvestmentEntryKind: string implements HasLabel
{
    case Disbursement = 'disbursement';
    case Impairment = 'impairment';
    case Closure = 'closure';

    public function getLabel(): string
    {
        return __('investments.entry_kind.'.$this->value);
    }
}
