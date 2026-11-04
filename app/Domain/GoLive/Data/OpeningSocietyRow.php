<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

use App\Support\Money\Money;

/**
 * A checked society balance: one allowed account and its opening amount.
 */
final readonly class OpeningSocietyRow
{
    public function __construct(public int $row, public string $accountCode, public Money $amount) {}
}
