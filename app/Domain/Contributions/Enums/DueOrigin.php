<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a due came from. Only regular dues attract late fees.
 */
enum DueOrigin: string implements HasColor, HasIcon, HasLabel
{
    /** Created by the monthly run, a share change or a late fee. */
    case Regular = 'regular';

    /** Arrears brought in from the old books by the go-live import. */
    case Opening = 'opening';

    /** A late joiner's catch-up months and entry premium, created when the member joined. */
    case BackDues = 'back_dues';

    public function getLabel(): string
    {
        return __('dues.origin.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Regular => 'gray',
            self::Opening => 'warning',
            self::BackDues => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Regular => Heroicon::OutlinedCalendar,
            self::Opening => Heroicon::OutlinedArchiveBox,
            self::BackDues => Heroicon::OutlinedUserPlus,
        };
    }
}
