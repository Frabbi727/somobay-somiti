<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Fiscal-year boundary math. The fiscal year runs 1 July – 30 June (BR-19) and is named by
 * its two calendar years, e.g. 1 July 2026 – 30 June 2027 is "2026-27".
 */
final class FiscalCalendar
{
    public const int FIRST_MONTH = 7;

    /**
     * The calendar year in which the fiscal year containing $moment starts (Asia/Dhaka).
     */
    public static function startYearFor(DateTimeInterface|YearMonth $moment): int
    {
        $month = $moment instanceof YearMonth ? $moment : YearMonth::fromDate($moment);

        return $month->month >= self::FIRST_MONTH ? $month->year : $month->year - 1;
    }

    public static function code(int $startYear): string
    {
        return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
    }

    public static function firstMonth(int $startYear): YearMonth
    {
        return YearMonth::of($startYear, self::FIRST_MONTH);
    }

    public static function lastMonth(int $startYear): YearMonth
    {
        return self::firstMonth($startYear)->addMonths(11);
    }

    public static function startsOn(int $startYear): CarbonImmutable
    {
        return self::firstMonth($startYear)->firstDay();
    }

    public static function endsOn(int $startYear): CarbonImmutable
    {
        return self::lastMonth($startYear)->lastDay();
    }

    /**
     * The twelve months of the fiscal year, July first.
     *
     * @return list<YearMonth>
     */
    public static function months(int $startYear): array
    {
        return YearMonth::range(self::firstMonth($startYear), self::lastMonth($startYear));
    }

    /**
     * The 1-based position of $month inside its fiscal year (July = 1, June = 12).
     */
    public static function periodSequence(YearMonth $month): int
    {
        return self::firstMonth(self::startYearFor($month))->monthsUntil($month) + 1;
    }
}
