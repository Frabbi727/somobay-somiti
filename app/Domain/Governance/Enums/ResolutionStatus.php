<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ResolutionStatus: string implements HasColor, HasIcon, HasLabel
{
    case Proposed = 'proposed';
    case Passed = 'passed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return __('governance.resolution_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'warning',
            self::Passed => 'success',
            self::Rejected => 'danger',
            self::Withdrawn => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Proposed => Heroicon::OutlinedClock,
            self::Passed => Heroicon::OutlinedCheckCircle,
            self::Rejected => Heroicon::OutlinedNoSymbol,
            self::Withdrawn => Heroicon::OutlinedArrowUturnLeft,
        };
    }
}
