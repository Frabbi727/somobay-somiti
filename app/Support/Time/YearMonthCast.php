<?php

declare(strict_types=1);

namespace App\Support\Time;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a DATE column holding the first day of a month to and from YearMonth.
 *
 * @implements CastsAttributes<YearMonth|null, mixed>
 */
final class YearMonthCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?YearMonth
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Column [%s] does not hold a date string.', $key));
        }

        return YearMonth::parse($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof YearMonth) {
            throw new InvalidArgumentException(sprintf('Attribute [%s] must be set with a %s instance.', $key, YearMonth::class));
        }

        return $value->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof YearMonth ? (string) $value : null;
    }
}
