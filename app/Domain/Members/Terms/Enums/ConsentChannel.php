<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ConsentChannel: string implements HasColor, HasIcon, HasLabel
{
    case Portal = 'portal';
    case App = 'app';
    case Office = 'office';

    public function getLabel(): string
    {
        return __('terms.channel.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Portal => 'info',
            self::App => 'primary',
            self::Office => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Portal => Heroicon::OutlinedGlobeAlt,
            self::App => Heroicon::OutlinedDevicePhoneMobile,
            self::Office => Heroicon::OutlinedBuildingOffice,
        };
    }
}
