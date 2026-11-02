<?php

declare(strict_types=1);

namespace App\Domain\Members\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MaritalStatus: string implements HasColor, HasIcon, HasLabel
{
    case Unmarried = 'unmarried';
    case Married = 'married';
    case Widowed = 'widowed';
    case Divorced = 'divorced';

    public function getLabel(): string
    {
        return __('members.marital_status.'.$this->value);
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Unmarried => Heroicon::OutlinedUser,
            self::Married => Heroicon::OutlinedUsers,
            self::Widowed, self::Divorced => Heroicon::OutlinedUserMinus,
        };
    }
}
