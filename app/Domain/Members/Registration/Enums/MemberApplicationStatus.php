<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MemberApplicationStatus: string implements HasColor, HasIcon, HasLabel
{
    case Invited = 'invited';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Statuses that hold the mobile number and may still sign in as an applicant.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Invited->value, self::Submitted->value, self::Returned->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    /** The applicant may change the details (before the first submit, or after a return). */
    public function isEditable(): bool
    {
        return $this === self::Invited || $this === self::Returned;
    }

    public function getLabel(): string
    {
        return __('registration.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invited => 'gray',
            self::Submitted => 'info',
            self::Returned => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Invited => Heroicon::OutlinedEnvelope,
            self::Submitted => Heroicon::OutlinedClock,
            self::Returned => Heroicon::OutlinedArrowUturnLeft,
            self::Approved => Heroicon::OutlinedCheckCircle,
            self::Rejected => Heroicon::OutlinedNoSymbol,
        };
    }
}
