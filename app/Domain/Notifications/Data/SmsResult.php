<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

final readonly class SmsResult
{
    private function __construct(
        public bool $ok,
        public ?string $providerMessageId,
        public ?string $error,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
