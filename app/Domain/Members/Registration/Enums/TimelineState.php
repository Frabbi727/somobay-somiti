<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TimelineState: string implements HasColor, HasIcon, HasLabel
{
    case Done = 'done';
    case Pending = 'pending';
    case Waiting = 'waiting';
    case Returned = 'returned';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __('registration.timeline_state.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::Pending => 'warning',
            self::Waiting => 'gray',
            self::Returned => 'warning',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Done => Heroicon::OutlinedCheckCircle,
            self::Pending => Heroicon::OutlinedClock,
            self::Waiting => Heroicon::OutlinedEllipsisHorizontalCircle,
            self::Returned => Heroicon::OutlinedArrowUturnLeft,
            self::Rejected => Heroicon::OutlinedXCircle,
        };
    }
}
