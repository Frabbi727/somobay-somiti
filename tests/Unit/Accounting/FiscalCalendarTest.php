<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\FiscalCalendar;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

it('puts July into the fiscal year that starts that year and June into the previous one', function (string $month, int $startYear): void {
    expect(FiscalCalendar::startYearFor(YearMonth::parse($month)))->toBe($startYear);
})->with([
    'first month' => ['2026-07', 2026],
    'december' => ['2026-12', 2026],
    'january' => ['2027-01', 2026],
    'last month' => ['2027-06', 2026],
    'june before' => ['2026-06', 2025],
]);

it('decides the fiscal year on the Dhaka calendar', function (): void {
    // 30 June 2026 23:00 Dhaka (17:00 UTC) is still 2025-26; 1 July 00:30 Dhaka (18:30 UTC on 30 June) is 2026-27.
    expect(FiscalCalendar::startYearFor(CarbonImmutable::parse('2026-06-30 17:00:00', 'UTC')))->toBe(2025)
        ->and(FiscalCalendar::startYearFor(CarbonImmutable::parse('2026-06-30 18:30:00', 'UTC')))->toBe(2026);
});

it('names and bounds fiscal years', function (): void {
    expect(FiscalCalendar::code(2026))->toBe('2026-27')
        ->and(FiscalCalendar::code(1999))->toBe('1999-00')
        ->and(FiscalCalendar::code(2099))->toBe('2099-00')
        ->and(FiscalCalendar::startsOn(2026)->toDateString())->toBe('2026-07-01')
        ->and(FiscalCalendar::endsOn(2026)->toDateString())->toBe('2027-06-30')
        ->and(FiscalCalendar::endsOn(2027)->toDateString())->toBe('2028-06-30');
});

it('lists the twelve months from July to June', function (): void {
    $months = array_map('strval', FiscalCalendar::months(2026));

    expect($months)->toHaveCount(12)
        ->and($months[0])->toBe('2026-07')
        ->and($months[5])->toBe('2026-12')
        ->and($months[6])->toBe('2027-01')
        ->and($months[11])->toBe('2027-06');
});

it('numbers periods from July', function (string $month, int $sequence): void {
    expect(FiscalCalendar::periodSequence(YearMonth::parse($month)))->toBe($sequence);
})->with([['2026-07', 1], ['2026-12', 6], ['2027-01', 7], ['2027-06', 12]]);
