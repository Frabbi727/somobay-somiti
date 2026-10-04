<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Actions\ApproveYearEnd;
use App\Domain\YearEnd\Actions\CreditDividendsToSavings;
use App\Domain\YearEnd\Actions\PrepareYearEnd;
use App\Domain\YearEnd\Actions\SettleDividend;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Enums\DividendSettlement;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Domain\YearEnd\Models\YearEnd;
use App\Enums\Role;
use App\Filament\Pages\Reports\DividendRegisterPage;
use App\Filament\Resources\YearEnds\Pages\ViewYearEnd;
use App\Filament\Resources\YearEnds\RelationManagers\DividendLinesRelationManager;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Phase 11 (P11.S3): dividends paid out (PV) or credited to savings (Dr 2201 / Cr 2101), once each.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    $fy = app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');

    $this->rahim = onboard(2, '2026-07');
    $this->karim = onboard(1, '2026-07');
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '12000', '2026-12-15'));

    travelTo('2027-07-05');

    foreach ($fy->periods()->orderBy('sequence')->get()->slice(0, -1) as $period) {
        app(LockPeriod::class)($this->accountant, $period);
    }

    $this->yearEnd = app(PrepareYearEnd::class)($this->accountant, $fy, AppropriationRates::defaults());
    app(ApproveYearEnd::class)($this->president, $this->yearEnd);
    app(ApproveYearEnd::class)(userWithRole(Role::Accountant), $this->yearEnd);

    $this->rahimLine = DividendLine::query()->where('member_id', $this->rahim->id)->sole();
    $this->karimLine = DividendLine::query()->where('member_id', $this->karim->id)->sole();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function settleRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('pays a dividend out in cash by payment voucher', function (): void {
    $before = glBalance('1101');
    $line = app(SettleDividend::class)($this->accountant, $this->rahimLine, DividendSettlement::Payout, PaymentMethod::Cash);

    expect($line->status)->toBe(DividendStatus::Paid)
        ->and($line->settlementEntry?->voucher_no)->toStartWith('PV-2027-28-')
        ->and(glBalance('1101'))->toBe($before + $this->rahimLine->amount_poisha->poisha)
        ->and(glBalance('2201'))->toBe($this->karimLine->amount_poisha->poisha);

    assertBooksTieOut();
});

it('credits a dividend to the member’s savings', function (): void {
    app(SettleDividend::class)($this->accountant, $this->karimLine, DividendSettlement::Savings);

    expect($this->karimLine->fresh()?->status)->toBe(DividendStatus::Credited)
        ->and(glBalance('2101'))->toBe($this->karimLine->amount_poisha->poisha);

    assertBooksTieOut();
});

it('settles each dividend only once', function (): void {
    app(SettleDividend::class)($this->accountant, $this->karimLine, DividendSettlement::Savings);

    expect(settleRule(fn () => app(SettleDividend::class)($this->accountant, $this->karimLine->fresh(), DividendSettlement::Payout, PaymentMethod::Cash)))
        ->toBe('year_end.errors.already_settled');
});

it('refuses a payout the paying account cannot cover', function (): void {
    expect(settleRule(fn () => app(SettleDividend::class)($this->accountant, $this->rahimLine, DividendSettlement::Payout, PaymentMethod::Bkash)))
        ->toBe('accounting.errors.insufficient_funds');
});

it('credits all unpaid dividends to savings in one go', function (): void {
    app(SettleDividend::class)($this->accountant, $this->rahimLine, DividendSettlement::Payout, PaymentMethod::Cash);

    expect(app(CreditDividendsToSavings::class)($this->accountant, $this->yearEnd))->toBe(['credited' => 1, 'failed' => 0])
        ->and(glBalance('2201'))->toBe(0);

    assertBooksTieOut();
});

it('settles from the year-end screen and prints the dividend register', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->accountant);

    Livewire::test(DividendLinesRelationManager::class, ['ownerRecord' => $this->yearEnd->fresh(), 'pageClass' => ViewYearEnd::class])
        ->callAction(TestAction::make('settle')->table($this->rahimLine), data: ['how' => 'savings', 'confirm_text' => $this->rahim->member_no])
        ->assertHasNoActionErrors();

    expect($this->rahimLine->fresh()?->status)->toBe(DividendStatus::Credited);

    Livewire::test(DividendLinesRelationManager::class, ['ownerRecord' => $this->yearEnd->fresh(), 'pageClass' => ViewYearEnd::class])
        ->callTableAction('creditAll', data: ['confirm_text' => __('confirm.word')])
        ->assertHasNoTableActionErrors();

    expect(YearEnd::query()->sole()->dividendLines()->where('status', DividendStatus::Unpaid)->count())->toBe(0);

    $this->actingAs(userWithRole(Role::Auditor));

    Livewire::test(DividendRegisterPage::class)
        ->fillForm(['year_end' => $this->yearEnd->id])
        ->callAction('excel')
        ->assertFileDownloaded('dividend-register-2026-27.xlsx');
});
