<?php

declare(strict_types=1);

namespace App\Domain\Investments\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * How often an investment is expected to pay. "None" records that returns stopped from a date
 * (e.g. the tenant left).
 */
enum ReturnFrequency: string implements HasColor, HasIcon, HasLabel
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';
    case None = 'none';

    /**
     * Months between payments; null when it pays once or not at all.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
            self::OneTime, self::None => null,
        };
    }

    public function getLabel(): string
    {
        return __('investments.returns.frequency.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::None ? 'gray' : 'info';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::None ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedArrowPath;
    }
}
