<?php

declare(strict_types=1);

namespace App\Domain\Exits\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExitReason: string implements HasLabel
{
    case Voluntary = 'voluntary';
    case Deceased = 'deceased';
    case Expelled = 'expelled';

    /**
     * A deceased member's settlement goes to the nominees by their shares.
     */
    public function paysNominees(): bool
    {
        return $this === self::Deceased;
    }

    public function getLabel(): string
    {
        return __('exits.reason.'.$this->value);
    }
}
