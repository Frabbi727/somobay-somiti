<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Filament\Support\Display;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * JSON shapes the mobile app relies on. Money is exact poisha plus the backend's own formatting,
 * so the app never parses or rounds an amount.
 */
final class ApiValue
{
    /**
     * @return array{poisha: int, display: string}|null
     */
    public static function money(?Money $money): ?array
    {
        return $money === null ? null : ['poisha' => $money->poisha, 'display' => Display::money($money)];
    }

    /**
     * @return array{value: string|int, label: string, color: string|null}|null
     */
    public static function enum(?BackedEnum $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $color = $value instanceof HasColor ? $value->getColor() : null;
        $label = $value instanceof HasLabel ? $value->getLabel() : null;

        return [
            'value' => $value->value,
            'label' => match (true) {
                $label instanceof Htmlable => strip_tags($label->toHtml()),
                is_string($label) => $label,
                default => (string) $value->value,
            },
            'color' => is_string($color) ? $color : null,
        ];
    }

    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    public static function month(?YearMonth $month): ?string
    {
        return $month === null ? null : substr($month->toDateString(), 0, 7);
    }

    public static function time(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(YearMonth::TIMEZONE)->toIso8601String();
    }
}
