<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\UnlockPeriod;
use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
});

it('opens a fiscal year with twelve open periods from July to June', function (): void {
    $year = app(OpenFiscalYear::class)($this->accountant, 2026);

    expect($year->code)->toBe('2026-27')
        ->and($year->starts_on->toDateString())->toBe('2026-07-01')
        ->and($year->ends_on->toDateString())->toBe('2027-06-30')
        ->and($year->status)->toBe(FiscalYearStatus::Open);

    $periods = $year->periods()->get();

    expect($periods)->toHaveCount(12)
        ->and($periods->pluck('sequence')->all())->toBe(range(1, 12))
        ->and($periods->map(fn ($period): string => (string) $period->month)->all())->toBe([
            '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12',
            '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06',
        ])
        ->and($periods->every(fn ($period): bool => $period->status === PeriodStatus::Open))->toBeTrue();
});

it('opens consecutive years forwards and backwards but not with gaps or twice', function (): void {
    $open = app(OpenFiscalYear::class);
    $open($this->accountant, 2026);
    $open($this->accountant, 2027);
    $open($this->accountant, 2025);

    expect(FiscalYear::query()->orderBy('start_year')->pluck('code')->all())->toBe(['2025-26', '2026-27', '2027-28'])
        ->and(fn () => $open($this->accountant, 2026))->toThrow(DomainRuleViolation::class, __('accounting.errors.fiscal_year_exists', ['code' => '2026-27']))
        ->and(fn () => $open($this->accountant, 2030))->toThrow(fn (DomainRuleViolation $e) => expect($e->translationKey)->toBe('accounting.errors.fiscal_year_gap'));
});

it('only lets super admins and accountants open years', function (): void {
    app(OpenFiscalYear::class)(userWithRole(Role::Cashier), 2026);
})->throws(AuthorizationException::class);

it('locks and unlocks a period', function (): void {
    $period = app(OpenFiscalYear::class)($this->accountant, 2026)->periods()->firstOrFail();

    $locked = app(LockPeriod::class)($this->accountant, $period);

    expect($locked->status)->toBe(PeriodStatus::Locked)
        ->and($locked->locked_by)->toBe($this->accountant->id)
        ->and($locked->locked_at)->not->toBeNull();

    $unlocked = app(UnlockPeriod::class)($this->president, $locked);

    expect($unlocked->status)->toBe(PeriodStatus::Open)
        ->and($unlocked->locked_at)->toBeNull();
});

it('closes a fiscal year by locking every period', function (): void {
    $year = app(OpenFiscalYear::class)($this->accountant, 2026);

    $closed = app(CloseFiscalYear::class)($this->president, $year);

    expect($closed->status)->toBe(FiscalYearStatus::Closed)
        ->and($closed->closed_by)->toBe($this->president->id)
        ->and($year->periods()->where('status', PeriodStatus::Open)->count())->toBe(0);
});

it('never re-opens a month of a closed year', function (): void {
    $year = app(OpenFiscalYear::class)($this->accountant, 2026);
    app(CloseFiscalYear::class)($this->president, $year);
    $period = $year->periods()->firstOrFail();

    expect($this->president->can('unlock', $period))->toBeFalse()
        ->and(fn () => app(UnlockPeriod::class)($this->president, $period))->toThrow(AuthorizationException::class);
});

it('requires earlier years to be closed first', function (): void {
    app(OpenFiscalYear::class)($this->accountant, 2025);
    $later = app(OpenFiscalYear::class)($this->accountant, 2026);

    app(CloseFiscalYear::class)($this->president, $later);
})->throws(DomainRuleViolation::class);

it('rejects closing a year twice', function (): void {
    $year = app(OpenFiscalYear::class)($this->accountant, 2026);
    app(CloseFiscalYear::class)($this->president, $year);

    expect($this->president->can('close', $year->fresh()))->toBeFalse();
});

it('does not let a cashier close a year', function (): void {
    $year = app(OpenFiscalYear::class)($this->accountant, 2026);

    app(CloseFiscalYear::class)(userWithRole(Role::Cashier), $year);
})->throws(AuthorizationException::class);
