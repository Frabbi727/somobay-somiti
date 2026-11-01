<?php

declare(strict_types=1);

namespace App\Domain\Members\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RegistrationDocument: string implements HasColor, HasIcon, HasLabel
{
    case Nid = 'nid';
    case Passport = 'passport';
    case NomineeNid = 'nominee_nid';
    case NomineePhoto = 'nominee_photo';

    public function getLabel(): string
    {
        return __('members.documents.'.$this->value);
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Nid, self::NomineeNid => Heroicon::OutlinedIdentification,
            self::Passport => Heroicon::OutlinedBookOpen,
            self::NomineePhoto => Heroicon::OutlinedPhoto,
        };
    }
}
