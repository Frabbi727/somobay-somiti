<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use LogicException;

/**
 * Thrown by model guards when code tries to change a record that is part of the permanent books.
 */
final class ImmutableRecord extends LogicException
{
    public static function for(string $model, int|string|null $key): self
    {
        return new self(sprintf('%s #%s is immutable. Reverse or supersede it instead.', $model, (string) $key));
    }
}
