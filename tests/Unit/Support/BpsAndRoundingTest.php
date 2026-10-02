<?php

declare(strict_types=1);

use App\Support\Money\Bps;
use App\Support\Money\Exceptions\InvalidMoneyAmount;
use App\Support\Money\Exceptions\MoneyOverflow;
use App\Support\Money\Rounding;

it('rounds basis-point fees HALF_UP to the poisha', function (int $base, int $bps, int $expected): void {
    expect(Rounding::halfUpBps($base, $bps))->toBe($expected);
})->with([
    'spec example 2% of 1005.25' => [100525, 200, 2011],
    'exact' => [50000, 200, 1000],
    'half rounds up' => [25, 200, 1],      // 0.5 → 1
    'below half rounds down' => [24, 200, 0], // 0.48 → 0
    'negative half rounds away from zero' => [-25, 200, -1],
    'zero rate' => [100525, 0, 0],
]);

it('matches the intdiv(base × bps + 5000, 10000) formula for non-negative bases', function (): void {
    mt_srand(20261002);

    for ($i = 0; $i < 1000; $i++) {
        $base = mt_rand(0, 100_000_000);
        $bps = mt_rand(0, 10_000);

        expect(Rounding::halfUpBps($base, $bps))->toBe(intdiv($base * $bps + 5000, 10000));
    }
});

it('rounds an arbitrary ratio HALF_UP', function (): void {
    expect(Rounding::halfUpRatio(1000, 1, 3))->toBe(333)
        ->and(Rounding::halfUpRatio(1000, 2, 3))->toBe(667);
});

it('throws when the rounded result overflows', function (): void {
    Rounding::halfUpBps(PHP_INT_MAX, 20_000);
})->throws(MoneyOverflow::class);

it('parses and formats percentages as basis points', function (string $input, int $bps, string $display): void {
    $value = Bps::ofPercent($input);

    expect($value->value)->toBe($bps)
        ->and($value->format('en'))->toBe($display);
})->with([
    ['2', 200, '2.00%'],
    ['2.5', 250, '2.50%'],
    ['2.00%', 200, '2.00%'],
    ['০.০৫', 5, '0.05%'],
    ['15', 1500, '15.00%'],
]);

it('formats percentages in Bangla digits', function (): void {
    expect(Bps::of(250)->format())->toBe('২.৫০%');
});

it('rejects negative or malformed percentages', function (Closure $make): void {
    $make();
})->throws(InvalidMoneyAmount::class)->with([
    'negative bps' => [fn () => Bps::of(-1)],
    'three decimals' => [fn () => Bps::ofPercent('1.234')],
    'text' => [fn () => Bps::ofPercent('ten')],
]);
