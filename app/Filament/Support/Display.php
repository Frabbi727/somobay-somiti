<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Bangla\BanglaDate;
use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Locale-aware display formatting for tables, infolists and confirmations.
 */
final class Display
{
    public static function isBangla(): bool
    {
        return app()->getLocale() === 'bn';
    }

    public static function date(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return self::isBangla()
            ? BanglaDate::date($date)
            : CarbonImmutable::instance($date)->setTimezone(YearMonth::TIMEZONE)->format('j M Y');
    }

    public static function dateTime(?DateTimeInterface $moment): string
    {
        if ($moment === null) {
            return '—';
        }

        $local = CarbonImmutable::instance($moment)->setTimezone(YearMonth::TIMEZONE);

        return self::date($local).' '.self::digits($local->format('H:i'));
    }

    public static function yearMonth(?YearMonth $month): string
    {
        if ($month === null) {
            return '—';
        }

        return self::isBangla() ? BanglaDate::yearMonth($month) : $month->firstDay()->format('F Y');
    }

    public static function money(?Money $money): string
    {
        return $money?->format(app()->getLocale()) ?? '—';
    }

    public static function digits(int|string $value): string
    {
        return self::isBangla() ? BanglaNumber::digits($value) : (string) $value;
    }

    /**
     * A debit-positive balance shown as an amount with its side, e.g. "৳ ৫০০.০০ ডেবিট".
     */
    public static function balance(Money $net): string
    {
        if ($net->isZero()) {
            return self::money($net);
        }

        return self::money($net->absolute()).' '.__($net->isPositive() ? 'reports.dr' : 'reports.cr');
    }
}
