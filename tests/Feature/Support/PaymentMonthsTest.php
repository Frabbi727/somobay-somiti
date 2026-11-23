<?php

declare(strict_types=1);

use App\Filament\Support\PaymentMonths;
use App\Support\Time\YearMonth;

it('joins consecutive months into ranges', function (): void {
    app()->setLocale('en');
    $months = array_map(fn (string $m): YearMonth => YearMonth::parse($m), ['2026-07', '2026-08', '2026-09', '2026-11', '2026-12', '2027-01']);

    expect(PaymentMonths::ranges($months))->toBe('Jul – Sep 2026, Nov 2026 – Jan 2027')
        ->and(PaymentMonths::ranges([YearMonth::parse('2026-07')]))->toBe('July 2026');
});

it('uses whole Bangla month names in ranges', function (): void {
    app()->setLocale('bn');
    $months = array_map(fn (string $m): YearMonth => YearMonth::parse($m), ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04']);

    // A three-letter cut would split a letter: "এপ্রিল" → "এপ্".
    expect(PaymentMonths::ranges($months))->toBe('ডিসেম্বর ২০২৫ – এপ্রিল ২০২৬');
});
