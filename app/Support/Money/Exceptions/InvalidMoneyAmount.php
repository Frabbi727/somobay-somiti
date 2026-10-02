<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

use InvalidArgumentException;

final class InvalidMoneyAmount extends InvalidArgumentException
{
    public static function unparseable(string $input): self
    {
        return new self(sprintf('Cannot parse [%s] as a taka amount with at most two decimals.', $input));
    }

    public static function unparseablePercent(string $input): self
    {
        return new self(sprintf('Cannot parse [%s] as a percentage with at most two decimals.', $input));
    }

    public static function negativeBps(int $bps): self
    {
        return new self(sprintf('Basis points must not be negative, [%d] given.', $bps));
    }

    public static function invalidWeights(string $reason): self
    {
        return new self('Invalid allocation weights: '.$reason);
    }
}
