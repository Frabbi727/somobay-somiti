<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What the s.34 percentages are taken of: the whole net profit, or what remains after the loss offset.
 */
enum StatutoryBase: string implements HasColor, HasIcon, HasLabel
{
    case NetProfit = 'net_profit';
    case AfterLossOffset = 'after_loss_offset';

    public function getLabel(): string
    {
        return __('financial_settings.statutory_base.'.$this->value);
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): Heroicon
    {
        return Heroicon::OutlinedScale;
    }
}
