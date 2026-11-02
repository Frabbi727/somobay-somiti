<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\CloseInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Actions\SetExpectedReturn;
use App\Domain\Investments\Data\ExpectedReturnData;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentReturnExpectation;
use App\Domain\Investments\Services\ExpectedReturns;
use App\Enums\Role;
use App\Filament\Pages\Reports\ExpectedReturnsPage;
use App\Filament\Resources\Investments\Pages\ViewInvestment;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| T-12: what an investment should pay (rent, profit) against what was received. Tracking only:
| nothing is posted, and income still enters the books only when it arrives.
*/

beforeEach(function (): void {
    travelTo('2026-11-20');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3101', '1000000', '2026-07-01'));

    $this->shop = app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, investmentData([
        'type' => 'shop',
        'name' => 'Shop no. 12, New Market',
        'institution' => 'New Market',
        'principal' => Money::ofTaka('1000000'),
        'invested_on' => '2026-07-10',
        'matures_on' => null,
        'expected_rate' => null,
    ])));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function expectReturn(User $actor, Investment $investment, string $frequency, string $taka, string $from, ?string $key = null): InvestmentReturnExpectation
{
    return app(SetExpectedReturn::class)($actor, $investment, ExpectedReturnData::fromForm([
        'frequency' => $frequency, 'amount' => $taka, 'starts_on' => $from, 'idempotency_key' => $key ?? (string) Str::uuid(),
    ]));
}

function rent(object $test, string $on, string $taka = '5000'): void
{
    app(RecordInvestmentIncome::class)($test->accountant, $test->shop, Money::ofTaka($taka), Money::zero(), CarbonImmutable::parse($on), PaymentMethod::Bank);
}

/**
 * @return list<array{0: string, 1: string}>
 */
function dueDates(Investment $investment, string $until): array
{
    return array_map(
        fn (array $period): array => [$period['due_on']->toDateString(), $period['amount']->toTakaString()],
        app(ExpectedReturns::class)->schedule($investment->fresh() ?? $investment, CarbonImmutable::parse($until)),
    );
}

it('compares expected rent with rent received, without posting anything', function (): void {
    $vouchers = JournalEntry::query()->count();
    expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-08-10');

    expect(JournalEntry::query()->count())->toBe($vouchers);

    rent($this, '2026-07-25');   // before rent was expected: not counted
    rent($this, '2026-08-12');
    rent($this, '2026-09-10');
    rent($this, '2026-10-11');

    $summary = app(ExpectedReturns::class)->summary($this->shop->fresh(), CarbonImmutable::parse('2026-11-20'));

    expect($summary['periods'])->toBe(4)
        ->and($summary['expected']->poisha)->toBe(2000000)
        ->and($summary['received']->poisha)->toBe(1500000)
        ->and($summary['difference']->poisha)->toBe(-500000)
        ->and($summary['behind_periods'])->toBe(1)
        ->and($summary['last_received_on']?->toDateString())->toBe('2026-10-11')
        ->and($summary['next_due']['due_on']->toDateString())->toBe('2026-12-10');
});

it('applies a rent rise from its date, lets a same-date entry correct it, and stops', function (): void {
    expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-08-10');
    expectReturn($this->accountant, $this->shop, 'monthly', '6500', '2026-10-10');
    expectReturn($this->accountant, $this->shop, 'monthly', '6000', '2026-10-10');   // correction

    expect(dueDates($this->shop, '2026-11-20'))->toBe([
        ['2026-08-10', '5000.00'], ['2026-09-10', '5000.00'], ['2026-10-10', '6000.00'], ['2026-11-10', '6000.00'],
    ]);

    expectReturn($this->accountant, $this->shop, 'none', '0', '2026-11-01');

    expect(dueDates($this->shop, '2026-12-31'))->toBe([['2026-08-10', '5000.00'], ['2026-09-10', '5000.00'], ['2026-10-10', '6000.00']])
        ->and(app(ExpectedReturns::class)->summary($this->shop->fresh(), CarbonImmutable::parse('2026-11-20'))['next_due'])->toBeNull();
});

it('steps month-end dates without drifting, and handles quarterly and one-time returns', function (): void {
    expectReturn($this->accountant, $this->shop, 'monthly', '1000', '2026-08-31');

    expect(array_column(dueDates($this->shop, '2026-11-30'), 0))->toBe(['2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30']);

    expectReturn($this->accountant, $this->shop, 'quarterly', '9000', '2026-08-31');
    expectReturn($this->accountant, $this->shop, 'one_time', '20000', '2027-01-15');

    expect(dueDates($this->shop, '2027-06-30'))->toBe([['2026-08-31', '9000.00'], ['2026-11-30', '9000.00'], ['2027-01-15', '20000.00']]);
});

it('ignores income whose voucher was reversed', function (): void {
    expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-08-10');
    rent($this, '2026-08-10');
    rent($this, '2026-09-10');
    $entry = $this->shop->fresh()?->income()->latest('id')->firstOrFail()->journalEntry;
    app(ReverseJournal::class)($this->president, $entry, 'Entered twice by mistake', null, onBehalfOfOwner: true);

    expect(app(ExpectedReturns::class)->summary($this->shop->fresh(), CarbonImmutable::parse('2026-09-30'))['received']->poisha)->toBe(500000);
});

it('expects nothing after the investment is sold', function (): void {
    expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-08-10');
    app(CloseInvestment::class)($this->president, $this->shop, Money::ofTaka('1000000'), CarbonImmutable::parse('2026-09-15'), PaymentMethod::Bank);

    $summary = app(ExpectedReturns::class)->summary($this->shop->fresh(), CarbonImmutable::parse('2026-11-20'));

    expect($summary['periods'])->toBe(2)
        ->and($summary['next_due'])->toBeNull()
        ->and(investmentRule(fn () => expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-10-10')))->toBe('investments.returns.errors.not_active');
});

it('refuses invalid expected returns', function (): void {
    $pending = app(RecordInvestment::class)($this->accountant, investmentData(['invested_on' => '2026-09-10']));

    expect(investmentRule(fn () => expectReturn($this->accountant, $pending, 'monthly', '100', '2026-10-01')))->toBe('investments.returns.errors.not_active')
        ->and(investmentRule(fn () => expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-07-01')))->toBe('investments.returns.errors.before_investment')
        ->and(investmentRule(fn () => expectReturn($this->accountant, $this->shop, 'none', '0', '2026-10-01')))->toBe('investments.returns.errors.nothing_to_stop')
        ->and(investmentRule(fn () => expectReturn($this->accountant, $this->shop, 'monthly', '0', '2026-10-01')))->toBe('investments.errors.amount_positive')
        ->and(fn () => expectReturn(userWithRole(Role::Cashier), $this->shop, 'monthly', '5000', '2026-10-01'))->toThrow(AuthorizationException::class);
});

it('returns the same row for a repeated submission and never edits a row', function (): void {
    $first = expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-08-10', 'b0f3c7a2-8a0d-4a8e-9a5e-6a1c2f7e1d01');
    $again = expectReturn($this->accountant, $this->shop, 'monthly', '5000', '2026-08-10', 'b0f3c7a2-8a0d-4a8e-9a5e-6a1c2f7e1d01');

    expect($again->id)->toBe($first->id)
        ->and(InvestmentReturnExpectation::query()->count())->toBe(1)
        ->and(fn () => DB::transaction(fn () => DB::table('investment_return_expectations')->where('id', $first->id)->update(['amount_poisha' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('investment_return_expectations')->where('id', $first->id)->delete()))->toThrow(QueryException::class);
});

it('sets an expected return from the investment page and lists it in the report', function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    $this->actingAs($this->accountant);

    Livewire::test(ViewInvestment::class, ['record' => $this->shop->getRouteKey()])
        ->callAction('setExpectedReturn', data: ['frequency' => 'monthly', 'amount' => '5,000', 'starts_on' => '2026-08-10'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('investments.returns.notifications.set', ['amount' => '৳ 5,000.00', 'frequency' => 'Monthly', 'date' => '10 Aug 2026']));

    rent($this, '2026-08-10');

    Livewire::test(ViewInvestment::class, ['record' => $this->shop->getRouteKey()])
        ->assertSee(__('investments.returns.title'))
        ->assertSee('৳ 20,000.00');

    $this->actingAs($this->president);

    Livewire::test(ExpectedReturnsPage::class)
        ->assertSee('Shop no. 12, New Market')
        ->assertSee('-৳ 15,000.00')
        ->assertSee(__('investments.returns.report.behind', ['count' => '3']));
});
