<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * A business rule was broken. The message is a translation key resolved in the current locale,
 * so the UI can show it directly as a danger notification.
 */
final class DomainRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    private function __construct(public readonly string $translationKey, public readonly array $replace)
    {
        $message = trans($translationKey, $replace);

        parent::__construct(is_string($message) ? $message : $translationKey);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $translationKey, array $replace = []): self
    {
        return new self($translationKey, $replace);
    }
}
