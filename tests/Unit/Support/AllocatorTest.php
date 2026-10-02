<?php

declare(strict_types=1);

use App\Support\Money\Allocator;
use App\Support\Money\Exceptions\InvalidMoneyAmount;

it('solves Foemmel’s conundrum deterministically', function (): void {
    // Floors are [3, 1]; both remainders are 0.5, so the tie goes to the first key.
    expect(Allocator::largestRemainder(5, [7, 3]))->toBe([4, 1]);
});

it('gives leftover poisha to the largest remainders', function (): void {
    // 100 split 1:1:1 → exact shares 33.33 each; the first key wins the tie.
    expect(Allocator::largestRemainder(100, [1, 1, 1]))->toBe([34, 33, 33])
        // 10 split 1:2:3 → 1.67, 3.33, 5.00 → floors 1, 3, 5; largest remainder is the first.
        ->and(Allocator::largestRemainder(10, [1, 2, 3]))->toBe([2, 3, 5]);
});

it('keeps keys and ignores zero weights', function (): void {
    expect(Allocator::largestRemainder(1000, ['m1' => 0, 'm2' => 3, 'm3' => 1]))
        ->toBe(['m1' => 0, 'm2' => 750, 'm3' => 250]);
});

it('allocates negative totals symmetrically', function (): void {
    expect(Allocator::largestRemainder(-5, [7, 3]))->toBe([-4, -1]);
});

it('does not overflow when total × weight exceeds 64 bits', function (): void {
    $parts = Allocator::largestRemainder(PHP_INT_MAX, [PHP_INT_MAX, PHP_INT_MAX]);

    expect(array_sum($parts))->toBe(PHP_INT_MAX)
        ->and($parts)->toBe([intdiv(PHP_INT_MAX, 2) + 1, intdiv(PHP_INT_MAX, 2)]);
});

it('rejects invalid weights', function (array $weights): void {
    Allocator::largestRemainder(100, $weights);
})->throws(InvalidMoneyAmount::class)->with([
    'empty' => [[]],
    'negative' => [[1, -1]],
    'all zero' => [[0, 0]],
]);

it('always sums exactly and stays within one poisha of each exact share (10,000 random cases)', function (): void {
    $seed = random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    for ($case = 0; $case < 10_000; $case++) {
        $total = mt_rand(0, 1_000_000_000);
        $weights = [];

        for ($i = 0, $count = mt_rand(1, 8); $i < $count; $i++) {
            $weights[] = mt_rand(0, 1000);
        }

        $weightSum = array_sum($weights);

        if ($weightSum === 0) {
            $weights[0] = 1;
            $weightSum = 1;
        }

        $parts = Allocator::largestRemainder($total, $weights);

        expect(array_sum($parts))->toBe($total, "seed {$seed}, case {$case}: sum mismatch");

        foreach ($parts as $key => $part) {
            // |part − total × w / Σw| < 1  ⇔  |part × Σw − total × w| < Σw
            $error = abs($part * $weightSum - $total * $weights[$key]);

            expect($error < $weightSum)->toBeTrue("seed {$seed}, case {$case}: part {$key} is more than 1 poisha off");
        }
    }
});
