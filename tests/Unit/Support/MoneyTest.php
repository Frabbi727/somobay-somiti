<?php

declare(strict_types=1);

use App\Support\Money\Bps;
use App\Support\Money\Exceptions\InvalidMoneyAmount;
use App\Support\Money\Exceptions\MoneyOverflow;
use App\Support\Money\Money;

it('adds ৳0.10 and ৳0.20 to exactly ৳0.30', function (): void {
    $sum = Money::ofTaka('0.1')->plus(Money::ofTaka('0.2'));

    expect($sum->equals(Money::ofTaka('0.3')))->toBeTrue()
        ->and($sum->poisha)->toBe(30);
});

it('parses taka text into poisha', function (string $input, int $poisha): void {
    expect(Money::ofTaka($input)->poisha)->toBe($poisha);
})->with([
    'whole taka' => ['1234', 123400],
    'one decimal' => ['1234.5', 123450],
    'two decimals' => ['1234.50', 123450],
    'grouped' => ['1,234.50', 123450],
    'lakh grouped' => ['12,34,567.89', 123456789],
    'bangla digits' => ['১,২৩৪.৫০', 123450],
    'with taka sign' => ['৳ 500', 50000],
    'surrounding spaces' => ['  20.05 ', 2005],
    'negative' => ['-20.05', -2005],
    'zero' => ['0', 0],
    'leading zeros' => ['007.70', 770],
]);

it('rejects text that is not a two-decimal taka amount', function (string $input): void {
    Money::ofTaka($input);
})->throws(InvalidMoneyAmount::class)->with([
    'empty' => [''],
    'three decimals' => ['1.005'],
    'trailing dot' => ['12.'],
    'leading dot' => ['.5'],
    'letters' => ['12a'],
    'exponent' => ['1e3'],
    'two dots' => ['1.2.3'],
    'only sign' => ['-'],
    'plus sign' => ['+5'],
]);

it('returns null from tryOfTaka for invalid or overflowing input', function (): void {
    expect(Money::tryOfTaka('abc'))->toBeNull()
        ->and(Money::tryOfTaka('99999999999999999999'))->toBeNull()
        ->and(Money::tryOfTaka('5.25')?->poisha)->toBe(525);
});

it('throws instead of silently overflowing', function (Closure $operation): void {
    $operation();
})->throws(MoneyOverflow::class)->with([
    'plus' => [fn () => Money::ofPoisha(PHP_INT_MAX)->plus(Money::ofPoisha(1))],
    'minus' => [fn () => Money::ofPoisha(PHP_INT_MIN)->minus(Money::ofPoisha(1))],
    'multiply' => [fn () => Money::ofPoisha(PHP_INT_MAX)->multipliedByInt(2)],
    'negate' => [fn () => Money::ofPoisha(PHP_INT_MIN)->negated()],
    'parse' => [fn () => Money::ofTaka('92233720368547758.08')],
]);

it('does arithmetic and comparisons on poisha', function (): void {
    $a = Money::ofTaka('500');
    $b = Money::ofTaka('120.50');

    expect($a->minus($b)->poisha)->toBe(37950)
        ->and($b->multipliedByInt(3)->poisha)->toBe(36150)
        ->and($a->compare($b))->toBe(1)
        ->and($b->compare($a))->toBe(-1)
        ->and($a->compare(Money::ofPoisha(50000)))->toBe(0)
        ->and($a->isGreaterThan($b))->toBeTrue()
        ->and($b->isLessThanOrEqualTo($a))->toBeTrue()
        ->and($a->min($b))->toBe($b)
        ->and($a->max($b))->toBe($a)
        ->and(Money::ofPoisha(-5)->absolute()->poisha)->toBe(5)
        ->and(Money::sum([$a, $b, Money::ofPoisha(-50)])->poisha)->toBe(62000)
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('takes 2% of ৳1,005.25 as ৳20.11 (HALF_UP)', function (): void {
    expect(Money::ofTaka('1005.25')->percentOfBps(Bps::of(200))->poisha)->toBe(2011);
});

it('splits money with the largest-remainder allocator', function (): void {
    $parts = Money::ofPoisha(5)->allocate(['a' => 7, 'b' => 3]);

    expect(array_map(fn (Money $part): int => $part->poisha, $parts))->toBe(['a' => 4, 'b' => 1]);
});

it('formats for display without rounding', function (int $poisha, string $locale, bool $lakh, string $expected): void {
    expect(Money::ofPoisha($poisha)->format($locale, $lakh))->toBe($expected);
})->with([
    'bangla' => [1234550, 'bn', false, '৳ ১২,৩৪৫.৫০'],
    'english' => [1234550, 'en', false, '৳ 12,345.50'],
    'lakh' => [123456789, 'en', true, '৳ 12,34,567.89'],
    'small' => [5, 'en', false, '৳ 0.05'],
    'negative' => [-200000, 'en', false, '-৳ 2,000.00'],
    'min int' => [PHP_INT_MIN, 'en', false, '-৳ 92,233,720,368,547,758.08'],
]);

it('produces a plain taka string for form state', function (): void {
    expect(Money::ofPoisha(123450)->toTakaString())->toBe('1234.50')
        ->and(Money::ofPoisha(-7)->toTakaString())->toBe('-0.07')
        ->and(Money::zero()->toTakaString())->toBe('0.00')
        ->and(json_encode(Money::ofPoisha(42)))->toBe('42');
});
