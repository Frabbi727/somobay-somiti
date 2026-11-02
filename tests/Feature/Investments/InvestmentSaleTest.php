<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\CloseInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Services\InvestmentRegister;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Selling an investment: the sale price less selling expenses against the book value is a gain on
| sale (4211) or a loss on sale (5202), kept apart from rent and other profit (4201). The principal
| that comes back is never counted as profit.
*/

beforeEach(function (): void {
    travelTo('2027-06-20');
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

it('keeps rent and the gain on sale apart: buy ৳10,00,000, rent ৳5,000 a month, sell for ৳15,00,000', function (): void {
    foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $month) {
        app(RecordInvestmentIncome::class)($this->accountant, $this->shop, Money::ofTaka('5000'), Money::zero(), CarbonImmutable::parse($month), PaymentMethod::Bank);
    }

    app(CloseInvestment::class)($this->president, $this->shop, Money::ofTaka('1500000'), CarbonImmutable::parse('2026-11-15'), PaymentMethod::Bank, saleExpenses: Money::ofTaka('20000'));

    $shop = $this->shop->fresh();

    expect($shop?->status)->toBe(InvestmentStatus::Closed)
        ->and($shop?->sale_price_poisha?->poisha)->toBe(150000000)
        ->and($shop?->sale_expenses_poisha?->poisha)->toBe(2000000)
        ->and($shop?->sale_gain_poisha?->poisha)->toBe(48000000)
        ->and($shop?->bookValue()->isZero())->toBeTrue()
        ->and(glBalance('1312'))->toBe(0)
        ->and(glBalance('4201'))->toBe(1500000)
        ->and(glBalance('4211'))->toBe(48000000)
        ->and(glBalance('1111'))->toBe(-(100000000 - 100000000 + 1500000 + 148000000));

    $register = app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-11-30'));

    expect($register['rows'][0]['income']->poisha)->toBe(1500000)
        ->and($register['rows'][0]['gain']->poisha)->toBe(48000000)
        ->and($register['gain']->poisha)->toBe(48000000)
        ->and(app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-10-31'))['rows'][0]['gain']->isZero())->toBeTrue();

    assertBooksTieOut();
});

it('posts a loss on sale to 5202 when the sale less expenses is below the book value', function (): void {
    app(CloseInvestment::class)($this->president, $this->shop, Money::ofTaka('950000'), CarbonImmutable::parse('2026-11-15'), PaymentMethod::Bank, saleExpenses: Money::ofTaka('10000'));

    expect($this->shop->fresh()?->sale_gain_poisha?->poisha)->toBe(-6000000)
        ->and(glBalance('5202'))->toBe(-6000000)
        ->and(glBalance('5201'))->toBe(0)
        ->and(glBalance('4211'))->toBe(0);

    assertBooksTieOut();
});

it('refuses selling expenses above the sale price', function (): void {
    expect(investmentRule(fn () => app(CloseInvestment::class)($this->president, $this->shop, Money::ofTaka('1000'), CarbonImmutable::parse('2026-11-15'), PaymentMethod::Bank, saleExpenses: Money::ofTaka('1000.01'))))
        ->toBe('investments.errors.sale_expenses')
        ->and($this->shop->fresh()?->status)->toBe(InvestmentStatus::Active);
});

it('keeps sale details off investments that are not closed', function (): void {
    expect(fn () => DB::table('investments')->whereKey($this->shop->id)->update(['sale_price_poisha' => 100]))->toThrow(QueryException::class);
});
