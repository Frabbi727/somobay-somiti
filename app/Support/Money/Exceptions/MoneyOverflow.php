<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

use OverflowException;

final class MoneyOverflow extends OverflowException
{
    public static function create(): self
    {
        return new self('Money amount exceeds the 64-bit poisha range.');
    }
}
