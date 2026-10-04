<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Abstentions do not count either way. Simple: more for than against. Two-thirds: at least
 * two thirds of those voting for or against are for.
 */
enum Majority: string implements HasLabel
{
    case Simple = 'simple';
    case TwoThirds = 'two_thirds';

    public function passes(int $for, int $against): bool
    {
        if ($for === 0) {
            return false;
        }

        return match ($this) {
            self::Simple => $for > $against,
            self::TwoThirds => $for * 3 >= ($for + $against) * 2,
        };
    }

    public function getLabel(): string
    {
        return __('governance.majority.'.$this->value);
    }
}
