<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The one thing the member should do now (spec §4); the apps choose their main button from it.
 */
enum RegistrationNextAction: string implements HasColor, HasIcon, HasLabel
{
    case Complete = 'complete';
    case Resubmit = 'resubmit';
    case Wait = 'wait';
    case None = 'none';

    public function getLabel(): string
    {
        return __('registration.next_action.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Complete, self::Resubmit => 'primary',
            self::Wait, self::None => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Complete => Heroicon::OutlinedPencilSquare,
            self::Resubmit => Heroicon::OutlinedArrowPath,
            self::Wait => Heroicon::OutlinedClock,
            self::None => Heroicon::OutlinedMinusCircle,
        };
    }
}
