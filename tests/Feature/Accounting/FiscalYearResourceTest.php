<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Enums\Role;
use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Filament\Resources\FiscalYears\Pages\ViewFiscalYear;
use App\Filament\Resources\FiscalYears\RelationManagers\PeriodsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('opens the current fiscal year when none exist', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));

    Livewire::test(ListFiscalYears::class)
        ->assertActionHidden('openPreviousFiscalYear')
        ->callAction('openNextFiscalYear')
        ->assertNotified(__('accounting.notifications.fiscal_year_opened', ['code' => '2026-27']));

    expect(FiscalYear::query()->sole()->code)->toBe('2026-27');
});

it('opens the next and previous years', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $this->actingAs($accountant);
    app(OpenFiscalYear::class)($accountant, 2026);

    Livewire::test(ListFiscalYears::class)
        ->callAction('openNextFiscalYear')
        ->callAction('openPreviousFiscalYear');

    expect(FiscalYear::query()->orderBy('start_year')->pluck('code')->all())->toBe(['2025-26', '2026-27', '2027-28']);
});

it('closes a year only after the code is typed', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $year = app(OpenFiscalYear::class)($accountant, 2026);
    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ListFiscalYears::class)
        ->callAction(TestAction::make('close')->table($year), data: ['confirm_text' => '2026'])
        ->assertHasActionErrors(['confirm_text']);

    expect($year->fresh()?->status)->toBe(FiscalYearStatus::Open);

    Livewire::test(ViewFiscalYear::class, ['record' => $year->getRouteKey()])
        ->callAction('close', data: ['confirm_text' => '2026-27'])
        ->assertHasNoActionErrors();

    expect($year->fresh()?->status)->toBe(FiscalYearStatus::Closed);
});

it('hides closing from a cashier', function (): void {
    $year = app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    $this->actingAs(userWithRole(Role::Cashier));

    Livewire::test(ListFiscalYears::class)
        ->assertActionHidden(TestAction::make('close')->table($year))
        ->assertActionHidden('openNextFiscalYear');
});

it('locks a month and unlocks it with the month typed', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $year = app(OpenFiscalYear::class)($accountant, 2026);
    $july = $year->periods()->firstOrFail();
    $this->actingAs($accountant);

    $manager = fn () => Livewire::test(PeriodsRelationManager::class, [
        'ownerRecord' => $year,
        'pageClass' => ViewFiscalYear::class,
    ]);

    $manager()
        ->assertCanSeeTableRecords($year->periods)
        ->assertActionHidden(TestAction::make('unlock')->table($july))
        ->callAction(TestAction::make('lock')->table($july));

    expect($july->fresh()?->status)->toBe(PeriodStatus::Locked);

    $manager()
        ->callAction(TestAction::make('unlock')->table($july->fresh()), data: ['confirm_text' => '2026-07'])
        ->assertHasNoActionErrors();

    expect($july->fresh()?->status)->toBe(PeriodStatus::Open);
});

it('shows Bangla month names on the fiscal year page', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $year = app(OpenFiscalYear::class)($accountant, 2026);
    app(LockPeriod::class)($accountant, $year->periods()->firstOrFail());
    app()->setLocale('bn');
    $this->actingAs($accountant);

    $this->get(route('filament.admin.resources.fiscal-years.view', $year))
        ->assertOk()
        ->assertSee('২০২৬-২৭');

    Livewire::test(PeriodsRelationManager::class, ['ownerRecord' => $year, 'pageClass' => ViewFiscalYear::class])
        ->assertSee('জুলাই ২০২৬')
        ->assertSee('জুন ২০২৭')
        ->assertSee('লক করা');
});
