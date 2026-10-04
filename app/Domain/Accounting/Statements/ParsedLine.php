<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Statements;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class ParsedLine
{
    public function __construct(
        public int $lineNo,
        public CarbonImmutable $date,
        public Money $amount,
        public ?string $description = null,
        public ?string $reference = null,
        public ?Money $balance = null,
    ) {}
}
