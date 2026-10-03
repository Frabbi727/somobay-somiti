<?php

declare(strict_types=1);

namespace App\Domain\Integrity;

final readonly class Finding
{
    /**
     * @param  array<string, int|string|null>  $context
     */
    public function __construct(
        public string $check,
        public string $message,
        public array $context = [],
    ) {}
}
