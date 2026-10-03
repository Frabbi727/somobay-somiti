<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\WaiveLateFee;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-02 10:00:00', 'Asia/Dhaka'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function lateFees($member): array
{
    return Due::query()->where('member_id', $member->id)->where('type', DueType::LateFee)->orderBy('id')
        ->get()->map(fn (Due $due): int => $due->amount_poisha->poisha)->all();
}

function applyOn(string $date): array
{
    return app(ApplyLateFees::class)(CarbonImmutable::parse($date, 'Asia/Dhaka'));
}

it('charges a fixed late fee once from the 16th and never twice', function (): void {
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);
    $member = onboard(2, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));

    expect(applyOn('2026-07-15')['count'])->toBe(0)
        ->and(applyOn('2026-07-16')['count'])->toBe(1)
        ->and(applyOn('2026-07-16')['count'])->toBe(0)
        ->and(applyOn('2026-09-30')['count'])->toBe(0)
        ->and(lateFees($member))->toBe([2000]);

    $fee = Due::query()->where('type', DueType::LateFee)->sole();
    $parent = Due::query()->find($fee->parent_due_id);

    expect($parent?->type)->toBe(DueType::Deposit)
        ->and((string) $fee->month)->toBe('2026-07')
        ->and($fee->due_date->toDateString())->toBe('2026-07-16');
});

it('charges a capped percentage every month until paid', function (): void {
    approvedPlan('2026-07', '500', [
        'late_fee_mode' => 'percent', 'late_fee_percent' => '2', 'late_fee_base' => 'deposit_plus_service',
        'late_fee_cap_poisha' => Money::ofTaka('50'), 'late_fee_frequency' => 'monthly_until_paid',
    ]);
    $member = onboard(2, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));

    applyOn('2026-07-16');
    applyOn('2026-08-16');
    applyOn('2026-09-16');
    applyOn('2026-10-16');

    // 2% of (1000 + 20) = 20.40, twice, then the ৳50 cap leaves 9.20, then nothing.
    expect(lateFees($member))->toBe([2040, 2040, 920]);
});

it('catches up missed charges in one run', function (): void {
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'monthly_until_paid']);
    $member = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));

    expect(applyOn('2026-09-20')['count'])->toBe(3)
        ->and(lateFees($member))->toBe([2000, 2000, 2000]);
});

it('does not charge members who have paid', function (): void {
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);
    $paid = onboard(1, '2026-07');
    $unpaid = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));

    Due::query()->where('member_id', $paid->id)->where('month', '2026-07-01')->get()
        ->each(fn (Due $due) => $due->forceFill(['paid_poisha' => $due->amount_poisha, 'status' => DueStatus::Settled])->save());

    applyOn('2026-07-16');

    expect(lateFees($paid))->toBe([])
        ->and(lateFees($unpaid))->toBe([2000]);
});

it('keeps the late fee rule of the month the due belongs to', function (): void {
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);
    approvedPlan('2026-08', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('50'), 'late_fee_frequency' => 'once']);
    $member = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-02 10:00:00', 'Asia/Dhaka'));
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 8));

    applyOn('2026-08-20');

    expect(lateFees($member))->toBe([2000, 5000]);
});

it('waives an unpaid late fee with a reason', function (): void {
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);
    onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));
    applyOn('2026-07-16');
    $fee = Due::query()->where('type', DueType::LateFee)->sole();

    expect(fn () => app(WaiveLateFee::class)(userWithRole(Role::Cashier), $fee, 'Member was in hospital'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(WaiveLateFee::class)(userWithRole(Role::Accountant), $fee, 'ok'))->toThrow(DomainRuleViolation::class);

    $waived = app(WaiveLateFee::class)(userWithRole(Role::Accountant), $fee, 'Member was in hospital');

    expect($waived->status)->toBe(DueStatus::Waived)
        ->and($waived->note)->toBe('Member was in hospital')
        ->and(applyOn('2026-07-20')['count'])->toBe(0);
});

it('runs from the command line', function (): void {
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);
    onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));

    $this->artisan('somiti:late-fees:apply', ['date' => '2026-07-16'])
        ->expectsOutputToContain('1 late fee(s) charged, ৳ 20.00')
        ->assertSuccessful();
});
