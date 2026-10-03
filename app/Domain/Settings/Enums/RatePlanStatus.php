<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RatePlanStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function getLabel(): string
    {
        return __('rates.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'warning',
            self::Approved => 'success',
            self::Cancelled => 'danger',
            self::Superseded => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencilSquare,
            self::PendingApproval => Heroicon::OutlinedClock,
            self::Approved => Heroicon::OutlinedCheckCircle,
            self::Cancelled => Heroicon::OutlinedXCircle,
            self::Superseded => Heroicon::OutlinedArchiveBox,
        };
    }
}
