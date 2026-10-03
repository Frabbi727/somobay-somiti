<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Events;

use App\Support\Money\Money;

final readonly class LateFeesApplied
{
    public function __construct(public int $count, public Money $total) {}
}
