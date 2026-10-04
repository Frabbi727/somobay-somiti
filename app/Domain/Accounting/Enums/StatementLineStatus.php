<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum StatementLineStatus: string implements HasColor, HasIcon, HasLabel
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return __('statements.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unmatched => 'warning',
            self::Matched => 'success',
            self::Ignored => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Unmatched => Heroicon::OutlinedQuestionMarkCircle,
            self::Matched => Heroicon::OutlinedLink,
            self::Ignored => Heroicon::OutlinedEyeSlash,
        };
    }
}
