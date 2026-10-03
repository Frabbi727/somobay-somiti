<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ReversePayment;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/*
| SOMITI_SPEC.md P5.S3 acceptance: 1 share at ৳500 (no service charge or registration fee).
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function simplePlan(string $month, string $unit, string $policy = 'apply_at_current_rate'): void
{
    approvedPlan($month, $unit, [
        'service_charge_per_share_poisha' => Money::zero(),
        'registration_fee_per_share_poisha' => Money::zero(),
        'advance_policy' => $policy,
    ]);
}

function paidThrough($member): ?string
{
    $month = app(PaidThroughCalculator::class)->for($member);

    return $month === null ? null : (string) $month;
}

it('1. holds ৳2,500 of a ৳3,000 July payment and settles August–December from it', function (): void {
    simplePlan('2026-07', '500');
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');

    travelTo('2026-07-05');
    receivePayment($member, '3000');

    expect(depositDues($member))->toBe(['2026-07' => [50000, 50000]])
        ->and(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(250000)
        ->and(app(PaidThroughCalculator::class)->estimatedMonths($member))->toBe(5);

    foreach (['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'] as $month) {
        generateMonth($month);
    }

    expect(collect(depositDues($member))->every(fn (array $due): bool => $due[0] === $due[1]))->toBeTrue()
        ->and(count(depositDues($member)))->toBe(6)
        ->and(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(0)
        ->and(paidThrough($member))->toBe('2026-12')
        ->and(glBalance('2111'))->toBe(0)
        ->and(glBalance('2101'))->toBe(300000);

    assertBooksTieOut();
});

it('2. applies the advance at each month\'s rate when the deposit rises to ৳600 from October', function (): void {
    simplePlan('2026-07', '500');
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '3000');
    simplePlan('2026-10', '600');

    foreach (['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'] as $month) {
        generateMonth($month);
    }

    // 2,500 − 500 (Aug) − 500 (Sep) − 600 (Oct) − 600 (Nov) = 300 left for December.
    // (The spec's text says "৳100 applied, ৳500 outstanding"; its arithmetic doesn't add up — 300/300 does.)
    expect(depositDues($member))->toBe([
        '2026-07' => [50000, 50000],
        '2026-08' => [50000, 50000],
        '2026-09' => [50000, 50000],
        '2026-10' => [60000, 60000],
        '2026-11' => [60000, 60000],
        '2026-12' => [60000, 30000],
    ])
        ->and(paidThrough($member))->toBe('2026-11')
        ->and(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(0);

    assertBooksTieOut();
});

it('3. locks August–December at ৳500 under the lock policy while January onward costs ৳600', function (): void {
    simplePlan('2026-07', '500', 'lock_prepaid_months');
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    $payment = receivePayment($member, '3000');

    expect($payment->advance_policy_at_payment?->value)->toBe('lock_prepaid_months')
        ->and(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(0)
        ->and(Due::query()->where('member_id', $member->id)->where('prepaid_locked', true)->count())->toBe(5);

    // Locked months don't count as generated, so the committee can still raise the rate from October.
    simplePlan('2026-10', '600');

    foreach (['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'] as $month) {
        generateMonth($month);
    }

    expect(depositDues($member))->toBe([
        '2026-07' => [50000, 50000],
        '2026-08' => [50000, 50000],
        '2026-09' => [50000, 50000],
        '2026-10' => [50000, 50000],
        '2026-11' => [50000, 50000],
        '2026-12' => [50000, 50000],
        '2027-01' => [60000, 0],
    ])
        ->and(paidThrough($member))->toBe('2026-12');

    assertBooksTieOut();
});

it('4. settles one month and carries ৳200 of a ৳700 payment into the next', function (): void {
    simplePlan('2026-07', '500');
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '700');

    generateMonth('2026-08');

    expect(depositDues($member))->toBe([
        '2026-07' => [50000, 50000],
        '2026-08' => [50000, 20000],
    ])
        ->and(paidThrough($member))->toBe('2026-07')
        ->and(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(0);

    assertBooksTieOut();
});

it('5. reverses a ৳3,000 payment after three advance applications and reopens every due', function (): void {
    simplePlan('2026-07', '500');
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    $payment = receivePayment($member, '3000');

    foreach (['2026-08', '2026-09', '2026-10'] as $month) {
        generateMonth($month);
    }

    expect(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(100000);

    travelTo('2026-10-10');
    app(ReversePayment::class)(userWithRole(Role::Accountant), $payment, 'Counterfeit notes found');

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Reversed)
        ->and(collect(depositDues($member))->every(fn (array $due): bool => $due[1] === 0))->toBeTrue()
        ->and(Due::query()->where('member_id', $member->id)->where('status', DueStatus::Settled)->count())->toBe(0)
        ->and(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(0)
        ->and(glBalance('2111'))->toBe(0)
        ->and(glBalance('2101'))->toBe(0)
        ->and(paidThrough($member))->toBeNull();

    assertBooksTieOut();
});

it('7. keeps every invariant through a mixed history', function (): void {
    simplePlan('2026-07', '500');
    $a = onboard(2, '2026-07');
    $b = onboard(1, '2026-07');
    generateMonth('2026-07');

    travelTo('2026-07-08');
    receivePayment($a, '1500', 'bkash', 'BK7XQ2P9A1');
    $toReverse = receivePayment($b, '1200');

    generateMonth('2026-08');
    travelTo('2026-08-09');
    receivePayment($b, '250', 'nagad', 'NG55AA77');
    app(ReversePayment::class)(userWithRole(Role::President), $toReverse, 'Entered for the wrong member');

    generateMonth('2026-09');

    assertBooksTieOut();
});
