<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RegistrationDecisionType: string implements HasColor, HasIcon, HasLabel
{
    case Approve = 'approve';
    case Return = 'return';
    case Reject = 'reject';

    public function getLabel(): string
    {
        return __('registration.decision.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approve => 'success',
            self::Return => 'warning',
            self::Reject => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Approve => Heroicon::OutlinedCheckCircle,
            self::Return => Heroicon::OutlinedArrowUturnLeft,
            self::Reject => Heroicon::OutlinedNoSymbol,
        };
    }
}
