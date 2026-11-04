<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum OpeningImportStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return __('golive.status.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Posted ? 'success' : 'warning';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::Posted ? Heroicon::OutlinedCheckBadge : Heroicon::OutlinedDocumentMagnifyingGlass;
    }
}
