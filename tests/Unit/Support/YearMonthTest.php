<?php

declare(strict_types=1);

use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

it('parses the short and stored forms', function (string $input): void {
    $month = YearMonth::parse($input);

    expect($month->year)->toBe(2026)
        ->and($month->month)->toBe(7)
        ->and((string) $month)->toBe('2026-07')
        ->and($month->toDateString())->toBe('2026-07-01');
})->with(['2026-07', '2026-07-01', '2026-07-01 00:00:00']);

it('rejects invalid months and non-first days', function (string $input): void {
    YearMonth::parse($input);
})->throws(InvalidArgumentException::class)->with(['2026-13', '2026-00', '2026-07-15', 'July 2026', '26-07']);

it('does month arithmetic across year boundaries', function (): void {
    $july = YearMonth::of(2026, 7);

    expect((string) $july->addMonths(6))->toBe('2027-01')
        ->and((string) $july->subMonths(7))->toBe('2025-12')
        ->and((string) YearMonth::of(2026, 12)->next())->toBe('2027-01')
        ->and((string) YearMonth::of(2026, 1)->previous())->toBe('2025-12')
        ->and($july->monthsUntil(YearMonth::of(2027, 6)))->toBe(11)
        ->and(YearMonth::of(2027, 6)->monthsUntil($july))->toBe(-11);
});

it('compares months', function (): void {
    $july = YearMonth::of(2026, 7);
    $august = YearMonth::of(2026, 8);

    expect($july->isBefore($august))->toBeTrue()
        ->and($august->isAfter($july))->toBeTrue()
        ->and($july->equals(YearMonth::parse('2026-07')))->toBeTrue()
        ->and($july->isSameOrBefore($july))->toBeTrue()
        ->and($july->compare($august))->toBe(-1);
});

it('lists an inclusive range of months', function (): void {
    $range = YearMonth::range(YearMonth::of(2026, 11), YearMonth::of(2027, 2));

    expect(array_map('strval', $range))->toBe(['2026-11', '2026-12', '2027-01', '2027-02'])
        ->and(YearMonth::range(YearMonth::of(2027, 2), YearMonth::of(2026, 11)))->toBe([]);
});

it('resolves days in Asia/Dhaka', function (): void {
    $month = YearMonth::of(2028, 2);

    expect($month->firstDay()->toDateTimeString())->toBe('2028-02-01 00:00:00')
        ->and($month->firstDay()->timezoneName)->toBe('Asia/Dhaka')
        ->and($month->lastDay()->toDateString())->toBe('2028-02-29')
        ->and($month->daysInMonth())->toBe(29)
        ->and($month->day(10)->toDateString())->toBe('2028-02-10');
});

it('rejects a day that the month does not have', function (): void {
    YearMonth::of(2026, 2)->day(30);
})->throws(InvalidArgumentException::class);

it('takes the month from the Asia/Dhaka wall clock', function (): void {
    // 30 June 2026 20:00 UTC is already 1 July 02:00 in Dhaka.
    $moment = CarbonImmutable::parse('2026-06-30 20:00:00', 'UTC');

    expect((string) YearMonth::fromDate($moment))->toBe('2026-07');
});

it('knows the current month', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', 'Asia/Dhaka'));

    expect((string) YearMonth::current())->toBe('2026-10');

    CarbonImmutable::setTestNow();
});
