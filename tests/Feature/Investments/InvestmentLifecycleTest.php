<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\CloseInvestment;
use App\Domain\Investments\Actions\ImpairInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Services\InvestmentRegister;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Pages\Reports\InvestmentRegisterPage;
use App\Filament\Resources\Investments\Pages\ViewInvestment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
| Phase 10 (P10.S2): profit (RV, tax at source), impairment (JV), closure (gain/loss), register report.
*/

beforeEach(function (): void {
    travelTo('2026-12-20');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3101', '200000', '2026-07-05'));

    $this->fdr = app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, InvestmentData::fromForm([
        'type' => 'fixed_deposit', 'institution' => 'Sonali Bank', 'instrument_no' => 'FDR-9',
        'principal' => Money::ofTaka('100000'), 'funded_from' => 'bank', 'invested_on' => '2026-07-10', 'matures_on' => '2026-12-10',
    ])));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function lifecycleRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('records profit gross, with tax deducted at source', function (): void {
    $income = app(RecordInvestmentIncome::class)($this->accountant, $this->fdr, Money::ofTaka('3500'), Money::ofTaka('350'), CarbonImmutable::parse('2026-10-10'), PaymentMethod::Bank, 'INT-OCT');

    expect($income->journalEntry->voucher_no)->toStartWith('RV-')
        ->and(glBalance('4201'))->toBe(350000)
        ->and(glBalance('5106'))->toBe(-35000)
        ->and(glBalance('1111'))->toBe(-(20000000 - 10000000 + 315000))
        ->and($this->fdr->fresh()?->bookValue()->poisha)->toBe(10000000);

    expect(lifecycleRule(fn () => app(RecordInvestmentIncome::class)($this->accountant, $this->fdr, Money::ofTaka('100'), Money::ofTaka('100'), CarbonImmutable::parse('2026-10-10'), PaymentMethod::Bank)))
        ->toBe('investments.errors.income_amounts');

    assertBooksTieOut();
});

it('closes at maturity with the capital returned and final profit in one go', function (): void {
    app(CloseInvestment::class)($this->accountant, $this->fdr, Money::ofTaka('100000'), CarbonImmutable::parse('2026-12-10'), PaymentMethod::Bank, Money::ofTaka('4200'), Money::ofTaka('420'));

    $fdr = $this->fdr->fresh();

    expect($fdr?->status)->toBe(InvestmentStatus::Closed)
        ->and($fdr?->bookValue()->isZero())->toBeTrue()
        ->and(glBalance('1301'))->toBe(0)
        ->and(glBalance('4201'))->toBe(420000)
        ->and(glBalance('1111'))->toBe(-(20000000 + 378000));

    assertBooksTieOut();
});

it('posts a loss or a gain when the capital returned differs from the book value', function (string $returned, string $code, int $balance): void {
    app(CloseInvestment::class)($this->accountant, $this->fdr, Money::ofTaka($returned), CarbonImmutable::parse('2026-12-10'), PaymentMethod::Bank);

    expect(glBalance($code))->toBe($balance);
    assertBooksTieOut();
})->with([
    'loss on early encashment' => ['98500', '5201', -150000],
    'gain on sale' => ['101000', '4201', 100000],
]);

it('writes an investment down by JV, never below zero, president only', function (): void {
    expect(fn () => app(ImpairInvestment::class)($this->accountant, $this->fdr, Money::ofTaka('1000'), 'Bank in trouble', CarbonImmutable::parse('2026-12-01')))
        ->toThrow(AuthorizationException::class);

    expect(lifecycleRule(fn () => app(ImpairInvestment::class)($this->president, $this->fdr, Money::ofTaka('100000.01'), 'Bank in trouble', CarbonImmutable::parse('2026-12-01'))))
        ->toBe('investments.errors.impairment_amount');

    app(ImpairInvestment::class)($this->president, $this->fdr, Money::ofTaka('40000'), 'Bank under liquidation', CarbonImmutable::parse('2026-12-01'));

    expect($this->fdr->fresh()?->bookValue()->poisha)->toBe(6000000)
        ->and(glBalance('5201'))->toBe(-4000000)
        ->and(glBalance('1301'))->toBe(-6000000);

    // Recovering ৳65,000 later is a ৳5,000 gain over the written-down book value.
    app(CloseInvestment::class)($this->accountant, $this->fdr, Money::ofTaka('65000'), CarbonImmutable::parse('2026-12-15'), PaymentMethod::Bank);

    expect(glBalance('4201'))->toBe(500000);
    assertBooksTieOut();
});

it('lists the register by kind and ties it to the 13xx accounts', function (): void {
    app(RecordInvestmentIncome::class)($this->accountant, $this->fdr, Money::ofTaka('3500'), Money::zero(), CarbonImmutable::parse('2026-10-10'), PaymentMethod::Bank);
    app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, InvestmentData::fromForm([
        'type' => 'savings_certificate', 'institution' => 'Sanchayapatra', 'principal' => Money::ofTaka('20000'), 'funded_from' => 'bank', 'invested_on' => '2026-11-01',
    ])));

    $register = app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-12-20'));

    expect($register['total']->poisha)->toBe(12000000)
        ->and($register['income']->poisha)->toBe(350000)
        ->and(collect($register['groups'])->every(fn (array $group): bool => $group['book']->equals($group['ledger'])))->toBeTrue();

    // As of before the savings certificate was bought, only the FDR shows.
    expect(app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-10-31'))['total']->poisha)->toBe(10000000);

    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Auditor));

    Livewire::test(InvestmentRegisterPage::class)
        ->fillForm(['as_of' => '2026-12-20'])
        ->callAction('pdf')
        ->assertFileDownloaded('investment-register-2026-12-20.pdf');
});

it('records profit and closes from the investment screen', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->accountant);

    Livewire::test(ViewInvestment::class, ['record' => $this->fdr->getRouteKey()])
        ->callAction('income', data: ['gross' => '1,250', 'tax_deducted' => '125', 'received_on' => '2026-11-10', 'received_into' => 'bank', 'confirm_text' => $this->fdr->investment_no])
        ->assertHasNoActionErrors()
        ->callAction('close', data: ['capital_returned' => '100,000', 'final_profit' => '0', 'tax_deducted' => '0', 'date' => '2026-12-10', 'received_into' => 'bank', 'confirm_text' => $this->fdr->investment_no])
        ->assertHasNoActionErrors();

    expect(Investment::query()->findOrFail($this->fdr->id)->status)->toBe(InvestmentStatus::Closed)
        ->and(glBalance('4201'))->toBe(125000);
});
