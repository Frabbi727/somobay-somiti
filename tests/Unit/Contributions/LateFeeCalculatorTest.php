<?php

declare(strict_types=1);

use App\Domain\Contributions\Services\LateFeeCalculator;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

function lateSnapshot(array $overrides = []): array
{
    return [
        'grace_days' => 5,
        'late_fee_mode' => 'percent',
        'late_fee_bps' => 200,
        'late_fee_fixed_poisha' => null,
        'late_fee_base' => 'deposit_only',
        'late_fee_cap_poisha' => null,
        'late_fee_frequency' => 'once',
        ...$overrides,
    ];
}

it('takes 2% of ৳1,005.25 as ৳20.11', function (): void {
    expect((new LateFeeCalculator)->fee(lateSnapshot(), Money::ofTaka('1005.25'), Money::zero())->poisha)->toBe(2011);
});

it('makes a due late from the day after due date plus grace', function (): void {
    $calculator = new LateFeeCalculator;
    $dueDate = CarbonImmutable::parse('2026-07-10');

    expect($calculator->lateFrom($dueDate, 5)->toDateString())->toBe('2026-07-16')
        ->and($calculator->chargesDue(lateSnapshot(), $dueDate, CarbonImmutable::parse('2026-07-15')))->toBe(0)
        ->and($calculator->chargesDue(lateSnapshot(), $dueDate, CarbonImmutable::parse('2026-07-16')))->toBe(1)
        ->and($calculator->chargesDue(lateSnapshot(), $dueDate, CarbonImmutable::parse('2026-12-31')))->toBe(1);
});

it('adds a charge per further month when charged monthly until paid', function (): void {
    $calculator = new LateFeeCalculator;
    $snapshot = lateSnapshot(['late_fee_frequency' => 'monthly_until_paid']);
    $dueDate = CarbonImmutable::parse('2026-07-10');

    expect($calculator->chargesDue($snapshot, $dueDate, CarbonImmutable::parse('2026-08-15')))->toBe(1)
        ->and($calculator->chargesDue($snapshot, $dueDate, CarbonImmutable::parse('2026-08-16')))->toBe(2)
        ->and($calculator->chargesDue($snapshot, $dueDate, CarbonImmutable::parse('2026-10-20')))->toBe(4)
        ->and($calculator->chargeDate($snapshot, $dueDate, 2)->toDateString())->toBe('2026-09-16');
});

it('caps the running total of percentage fees', function (): void {
    $calculator = new LateFeeCalculator;
    $snapshot = lateSnapshot(['late_fee_cap_poisha' => 5000]);

    expect($calculator->fee($snapshot, Money::ofTaka('1020'), Money::ofTaka('40.80'))->poisha)->toBe(920)
        ->and($calculator->fee($snapshot, Money::ofTaka('1020'), Money::ofTaka('50'))->poisha)->toBe(0);
});

it('charges a fixed fee regardless of the base', function (): void {
    $snapshot = lateSnapshot(['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => 2000, 'late_fee_bps' => null]);

    expect((new LateFeeCalculator)->fee($snapshot, Money::ofTaka('1'), Money::zero())->poisha)->toBe(2000);
});

it('charges nothing without a late fee', function (): void {
    expect((new LateFeeCalculator)->chargesDue(lateSnapshot(['late_fee_mode' => 'none']), CarbonImmutable::parse('2026-07-10'), CarbonImmutable::parse('2027-01-01')))->toBe(0);
});
