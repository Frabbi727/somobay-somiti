<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

final readonly class VoucherNumber
{
    public function __construct(
        public string $number,
        public int $sequence,
        public ?string $previousHash,
    ) {}
}
