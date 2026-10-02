<?php

declare(strict_types=1);

use App\Support\Bangla\BanglaDate;
use App\Support\Bangla\BanglaNumber;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

it('converts digits both ways', function (): void {
    expect(BanglaNumber::digits('৳ 12,345.50'))->toBe('৳ ১২,৩৪৫.৫০')
        ->and(BanglaNumber::digits(2026))->toBe('২০২৬')
        ->and(BanglaNumber::toAscii('১২,৩৪৫.৫০'))->toBe('12,345.50');
});

it('groups digits in western and lakh styles', function (string $digits, bool $lakh, string $expected): void {
    expect(BanglaNumber::group($digits, $lakh))->toBe($expected);
})->with([
    ['5', false, '5'],
    ['123', false, '123'],
    ['1234', false, '1,234'],
    ['1234567', false, '1,234,567'],
    ['1234567', true, '12,34,567'],
    ['123456789', true, '12,34,56,789'],
    ['12345', true, '12,345'],
]);

it('formats integers per locale', function (): void {
    expect(BanglaNumber::format(1234567))->toBe('১,২৩৪,৫৬৭')
        ->and(BanglaNumber::format(1234567, 'bn', true))->toBe('১২,৩৪,৫৬৭')
        ->and(BanglaNumber::format(-1500, 'en'))->toBe('-1,500');
});

it('writes dates and months in Bangla', function (): void {
    expect(BanglaDate::date(CarbonImmutable::parse('2026-10-02 10:00:00', 'Asia/Dhaka')))->toBe('২ অক্টোবর ২০২৬')
        ->and(BanglaDate::yearMonth(YearMonth::of(2026, 12)))->toBe('ডিসেম্বর ২০২৬')
        ->and(BanglaDate::monthName(1))->toBe('জানুয়ারি');
});

it('uses the Dhaka date for UTC moments', function (): void {
    expect(BanglaDate::date(CarbonImmutable::parse('2026-06-30 20:00:00', 'UTC')))->toBe('১ জুলাই ২০২৬');
});

it('rejects an invalid month number', function (): void {
    BanglaDate::monthName(13);
})->throws(InvalidArgumentException::class);
