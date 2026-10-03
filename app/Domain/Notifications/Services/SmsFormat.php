<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Support\Bangla\BanglaDate;
use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use DateTimeInterface;

/**
 * Formats values for Bangla SMS bodies (members receive Bangla by default).
 */
final class SmsFormat
{
    public static function money(Money $money): string
    {
        return $money->format('bn');
    }

    public static function month(YearMonth $month): string
    {
        return BanglaDate::yearMonth($month);
    }

    public static function date(DateTimeInterface $date): string
    {
        return BanglaDate::date($date);
    }

    public static function digits(int|string $value): string
    {
        return BanglaNumber::digits($value);
    }
}
