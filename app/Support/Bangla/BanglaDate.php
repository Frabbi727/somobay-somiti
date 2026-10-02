<?php

declare(strict_types=1);

namespace App\Support\Bangla;

use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Gregorian dates written in Bangla, e.g. "২ অক্টোবর ২০২৬" and "ডিসেম্বর ২০২৬".
 */
final class BanglaDate
{
    private const array MONTHS = [
        1 => 'জানুয়ারি',
        2 => 'ফেব্রুয়ারি',
        3 => 'মার্চ',
        4 => 'এপ্রিল',
        5 => 'মে',
        6 => 'জুন',
        7 => 'জুলাই',
        8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর',
        10 => 'অক্টোবর',
        11 => 'নভেম্বর',
        12 => 'ডিসেম্বর',
    ];

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? throw new InvalidArgumentException(sprintf('Month must be between 1 and 12, [%d] given.', $month));
    }

    /**
     * A full date in Asia/Dhaka, e.g. "২ অক্টোবর ২০২৬".
     */
    public static function date(DateTimeInterface $date): string
    {
        $local = CarbonImmutable::instance($date)->setTimezone(YearMonth::TIMEZONE);

        return BanglaNumber::digits($local->day).' '.self::monthName($local->month).' '.BanglaNumber::digits($local->year);
    }

    /**
     * A month and year, e.g. "ডিসেম্বর ২০২৬".
     */
    public static function yearMonth(YearMonth $yearMonth): string
    {
        return self::monthName($yearMonth->month).' '.BanglaNumber::digits($yearMonth->year);
    }
}
