<?php

declare(strict_types=1);

namespace App\Domain\Members\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum Gender: string implements HasColor, HasIcon, HasLabel
{
    case Male = 'male';
    case Female = 'female';

    public function getLabel(): string
    {
        return __('members.gender.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Male => 'info',
            self::Female => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Male => Heroicon::OutlinedUser,
            self::Female => Heroicon::OutlinedUserCircle,
        };
    }
}
