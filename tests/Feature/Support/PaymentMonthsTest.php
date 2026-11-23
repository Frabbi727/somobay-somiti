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
