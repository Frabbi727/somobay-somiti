<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Illuminate\Database\Eloquent\Model;

function castModel(): Model
{
    return new class extends Model {};
}

it('casts poisha columns to Money and back', function (): void {
    $cast = new MoneyCast;

    expect($cast->get(castModel(), 'amount_poisha', 123450, [])?->poisha)->toBe(123450)
        ->and($cast->get(castModel(), 'amount_poisha', '-500', [])?->poisha)->toBe(-500)
        ->and($cast->get(castModel(), 'amount_poisha', null, []))->toBeNull()
        ->and($cast->set(castModel(), 'amount_poisha', Money::ofPoisha(99), []))->toBe(99)
        ->and($cast->set(castModel(), 'amount_poisha', null, []))->toBeNull()
        ->and($cast->serialize(castModel(), 'amount_poisha', Money::ofPoisha(7), []))->toBe(7);
});

it('refuses to store anything but Money', function (mixed $value): void {
    (new MoneyCast)->set(castModel(), 'amount_poisha', $value, []);
})->throws(InvalidArgumentException::class)->with([
    'int' => [500],
    'float' => [5.0],
    'string' => ['5.00'],
]);

it('refuses to read a non-integer column', function (): void {
    (new MoneyCast)->get(castModel(), 'amount_poisha', '12.50', []);
})->throws(InvalidArgumentException::class);

it('casts first-of-month dates to YearMonth and back', function (): void {
    $cast = new YearMonthCast;

    expect((string) $cast->get(castModel(), 'month', '2026-07-01', []))->toBe('2026-07')
        ->and($cast->get(castModel(), 'month', null, []))->toBeNull()
        ->and($cast->set(castModel(), 'month', YearMonth::of(2026, 7), []))->toBe('2026-07-01')
        ->and($cast->serialize(castModel(), 'month', YearMonth::of(2026, 7), []))->toBe('2026-07');
});

it('refuses to store anything but YearMonth', function (): void {
    (new YearMonthCast)->set(castModel(), 'month', '2026-07-01', []);
})->throws(InvalidArgumentException::class);
