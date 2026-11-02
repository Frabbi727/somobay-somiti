<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\ApproveInvestmentTopUp;
use App\Domain\Investments\Actions\ImpairInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Actions\RecordInvestmentTopUp;
use App\Domain\Investments\Actions\ReverseInvestment;
use App\Domain\Investments\Actions\ReverseInvestmentTopUp;
use App\Domain\Investments\Data\InvestmentTopUpData;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Investments\Services\InvestmentRegister;
use App\Enums\Role;
use App\Filament\Resources\Investments\Pages\ViewInvestment;
use App\Filament\Resources\Investments\RelationManagers\TopUpsRelationManager;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Undoing an investment or an added amount approved by mistake: a mirror voucher, a "reversal"
| register entry, and the record kept, marked reversed. Someone other than the approver does it.
*/

beforeEach(function (): void {
    travelTo('2026-09-15');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3101', '1200000', '2026-07-01'));

    $this->shop = app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, investmentData([
        'type' => 'shop', 'name' => 'Shop no. 12', 'institution' => 'New Market', 'principal' => Money::ofTaka('1000000'),
        'invested_on' => '2026-07-10', 'matures_on' => null, 'expected_rate' => null,
    ])));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function approvedTopUp(Investment $investment, string $taka): InvestmentTopUp
{
    $topUp = app(RecordInvestmentTopUp::class)(userWithRole(Role::Accountant), $investment, InvestmentTopUpData::fromForm([
        'amount' => Money::ofTaka($taka), 'funded_from' => 'bank', 'invested_on' => '2026-08-10',
    ]));

    return app(ApproveInvestmentTopUp::class)(userWithRole(Role::President), $topUp);
}

it('reverses an investment: the money comes back and the record stays, marked reversed', function (): void {
    $investment = app(ReverseInvestment::class)($this->accountant, $this->shop, 'Entered against the wrong shop');

    expect($investment->status)->toBe(InvestmentStatus::Reversed)
        ->and($investment->reversed_by)->toBe($this->accountant->id)
        ->and($investment->reversal_reason)->toBe('Entered against the wrong shop')
        ->and($investment->bookValue()->isZero())->toBeTrue()
        ->and($investment->totalInvested()->isZero())->toBeTrue()
        ->and(glBalance('1312'))->toBe(0)
        ->and(glBalance('1111'))->toBe(-120000000)
        ->and($investment->ledger()->orderBy('id')->pluck('kind')->all())->toBe([InvestmentEntryKind::Disbursement, InvestmentEntryKind::Reversal]);

    // As of before the reversal it still shows; afterwards it does not.
    expect(app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-08-31'))['total']->poisha)->toBe(100000000)
        ->and(app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-09-15'))['rows'])->toBe([]);

    assertBooksTieOut();
});

it('needs someone other than the approver, with the permission, and the database agrees', function (): void {
    expect(investmentRule(fn () => app(ReverseInvestment::class)($this->president, $this->shop, 'My own mistake')))->toBe('investments.errors.reverse_own_approval')
        ->and(fn () => app(ReverseInvestment::class)(userWithRole(Role::Secretary), $this->shop, 'Not allowed'))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('investments')->where('id', $this->shop->id)->update(['reversed_by' => $this->president->id])))->toThrow(QueryException::class);
});

it('reverses added amounts one by one, and the investment only once they are dealt with', function (): void {
    $topUp = approvedTopUp($this->shop, '100000');

    expect(investmentRule(fn () => app(ReverseInvestment::class)($this->accountant, $this->shop, 'Wrong shop entirely')))->toBe('investments.errors.reverse_top_ups_first');

    $reversed = app(ReverseInvestmentTopUp::class)($this->accountant, $topUp, 'Second payment never made');

    expect($reversed->status)->toBe(InvestmentTopUpStatus::Reversed)
        ->and($this->shop->totalInvested()->poisha)->toBe(100000000)
        ->and(glBalance('1312'))->toBe(-100000000)
        ->and(investmentRule(fn () => app(ReverseInvestmentTopUp::class)($this->accountant, $topUp, 'Again please')))->toBe('investments.errors.top_up_not_approved');

    expect(app(ReverseInvestment::class)($this->accountant, $this->shop, 'Wrong shop entirely')->status)->toBe(InvestmentStatus::Reversed);

    assertBooksTieOut();
});

it('refuses once profit or a write-down is recorded', function (string $what): void {
    match ($what) {
        'profit' => app(RecordInvestmentIncome::class)($this->accountant, $this->shop, Money::ofTaka('5000'), Money::zero(), CarbonImmutable::parse('2026-08-10'), PaymentMethod::Bank),
        'write-down' => app(ImpairInvestment::class)($this->president, $this->shop, Money::ofTaka('1000'), 'Roof damaged in storm', CarbonImmutable::parse('2026-08-10')),
    };

    expect(investmentRule(fn () => app(ReverseInvestment::class)($this->accountant, $this->shop, 'Wrong shop entirely')))->toBe('investments.errors.reverse_has_activity')
        ->and($this->shop->fresh()?->status)->toBe(InvestmentStatus::Active);
})->with(['profit', 'write-down']);

it('is reversed from the investment page and the added-amounts list', function (): void {
    $topUp = approvedTopUp($this->shop, '100000');
    $this->actingAs($this->accountant);

    Livewire::test(TopUpsRelationManager::class, ['ownerRecord' => $this->shop, 'pageClass' => ViewInvestment::class])
        ->callAction(TestAction::make('reverseTopUp')->table($topUp), data: ['reason' => 'Never paid', 'confirm_text' => $this->shop->investment_no])
        ->assertHasNoActionErrors();

    Livewire::test(ViewInvestment::class, ['record' => $this->shop->getKey()])
        ->callAction('reverse', data: ['reason' => 'Wrong shop entirely', 'confirm_text' => $this->shop->investment_no])
        ->assertHasNoActionErrors();

    expect($topUp->fresh()?->status)->toBe(InvestmentTopUpStatus::Reversed)
        ->and($this->shop->fresh()?->status)->toBe(InvestmentStatus::Reversed);
});
