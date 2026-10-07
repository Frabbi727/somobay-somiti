<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Who is behind a member-side login: a member, or someone still registering.
 */
enum AccountType: string implements HasColor, HasIcon, HasLabel
{
    case Member = 'member';
    case Applicant = 'applicant';

    public function getLabel(): string
    {
        return __('portal.account_type.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Member ? 'success' : 'warning';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::Member ? Heroicon::OutlinedUser : Heroicon::OutlinedUserPlus;
    }
}
