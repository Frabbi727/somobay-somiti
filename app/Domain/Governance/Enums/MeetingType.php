<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MeetingType: string implements HasColor, HasIcon, HasLabel
{
    case Committee = 'committee';
    case General = 'general';
    case SpecialGeneral = 'special_general';

    /**
     * General meetings are attended by members; committee meetings by the managing committee.
     */
    public function isOfMembers(): bool
    {
        return $this !== self::Committee;
    }

    public function getLabel(): string
    {
        return __('governance.meeting_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Committee => 'info',
            self::General => 'primary',
            self::SpecialGeneral => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Committee => Heroicon::OutlinedUserGroup,
            self::General, self::SpecialGeneral => Heroicon::OutlinedBuildingLibrary,
        };
    }
}
