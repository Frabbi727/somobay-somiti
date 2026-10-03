<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Enums\Role;
use App\Filament\Pages\Dues\GenerateDues;
use App\Filament\Resources\Dues\DueResource;
use App\Filament\Resources\Dues\Pages\ListDues;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Asia/Dhaka'));
    approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);
    $this->member = onboard(2, '2026-07');
    $this->accountant = userWithRole(Role::Accountant);
    $this->actingAs($this->accountant);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('previews a month and generates it after the month is typed', function (): void {
    Livewire::test(GenerateDues::class)
        ->fillForm(['month' => '2026-07'])
        ->assertSee('RP-2026-07-v1')
        ->assertSee('৳ 1,020.00')
        ->callAction('run', data: ['confirm_text' => '2026-06'])
        ->assertHasActionErrors(['confirm_text']);

    expect(Due::query()->where('type', DueType::Deposit)->count())->toBe(0);

    Livewire::test(GenerateDues::class)
        ->fillForm(['month' => '2026-07'])
        ->callAction('run', data: ['confirm_text' => '2026-07'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('dues.generate.queued', ['month' => 'July 2026']));

    // The test queue runs jobs synchronously.
    expect(Due::query()->where('type', DueType::Deposit)->sole()->amount_poisha->poisha)->toBe(100000)
        ->and($this->accountant->notifications()->count())->toBe(1);

    Livewire::test(GenerateDues::class)
        ->fillForm(['month' => '2026-07'])
        ->assertSee(__('dues.generate.nothing_new'))
        ->assertActionDisabled('run');
});

it('explains why a month cannot be generated', function (): void {
    Livewire::test(GenerateDues::class)
        ->fillForm(['month' => '2026-06'])
        ->assertSee(__('members.errors.no_rate_plan', ['month' => '2026-06']))
        ->assertActionDisabled('run');
});

it('lists open dues with totals and charges late fees on demand', function (): void {
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));

    Livewire::test(ListDues::class)
        ->assertCanSeeTableRecords(Due::query()->get())
        ->assertSee('৳ 1,220.00')
        ->callAction('applyLateFees', data: ['confirm_text' => __('confirm.word')])
        ->assertNotified(__('dues.late_fees.applied', ['count' => '1', 'amount' => '৳ 20.00']));

    expect(Due::query()->where('type', DueType::LateFee)->count())->toBe(1);
});

it('waives a late fee after the member number is typed', function (): void {
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));
    app(ApplyLateFees::class)();
    $fee = Due::query()->where('type', DueType::LateFee)->sole();

    Livewire::test(ListDues::class)
        ->callAction(TestAction::make('waive')->table($fee), data: ['reason' => 'Bank holiday delay', 'confirm_text' => $this->member->member_no])
        ->assertHasNoActionErrors();

    expect($fee->fresh()?->status)->toBe(DueStatus::Waived);
});

it('lets the cashier see dues but not generate them or waive fees', function (): void {
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));
    app(ApplyLateFees::class)();
    $fee = Due::query()->where('type', DueType::LateFee)->sole();
    $this->actingAs(userWithRole(Role::Cashier));

    Livewire::test(ListDues::class)
        ->assertActionHidden('applyLateFees')
        ->assertActionHidden('generate')
        ->assertActionHidden(TestAction::make('waive')->table($fee));

    $this->get(GenerateDues::getUrl())->assertForbidden();
});

it('renders the dues pages in Bangla', function (): void {
    app(GenerateMonthlyDues::class)(YearMonth::of(2026, 7));
    app()->setLocale('bn');

    $this->get(DueResource::getUrl('index'))->assertOk()->assertSee('জুলাই ২০২৬');
    $this->get(GenerateDues::getUrl())->assertOk()->assertSee('বকেয়া তৈরি');
});
