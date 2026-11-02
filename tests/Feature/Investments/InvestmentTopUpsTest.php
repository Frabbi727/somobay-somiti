<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\ApproveInvestmentTopUp;
use App\Domain\Investments\Actions\CancelInvestmentTopUp;
use App\Domain\Investments\Actions\CloseInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentTopUp;
use App\Domain\Investments\Actions\RejectInvestmentTopUp;
use App\Domain\Investments\Data\InvestmentTopUpData;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Investments\Services\InvestmentRegister;
use App\Domain\Reporting\ApprovalQueue;
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
| More money put into an active investment: recorded by one person, approved by another, paid out
| by voucher and added to the investment's register.
*/

beforeEach(function (): void {
    travelTo('2026-09-15');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);

    // ৳12,00,000 in the bank.
    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3101', '1200000', '2026-07-01'));

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

/**
 * @param  array<string, mixed>  $overrides
 */
function topUpData(array $overrides = []): InvestmentTopUpData
{
    return InvestmentTopUpData::fromForm([
        'amount' => Money::ofTaka('100000'),
        'funded_from' => 'bank',
        'invested_on' => '2026-08-10',
        ...$overrides,
    ]);
}

it('adds approved top-ups to the investment: ৳10,00,000 + ৳1,00,000 + ৳50,000 = ৳11,50,000', function (): void {
    $first = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData());
    $second = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData(['amount' => Money::ofTaka('50000'), 'invested_on' => '2026-09-10']));

    expect($first->status)->toBe(InvestmentTopUpStatus::Pending)
        ->and($this->shop->totalInvested()->poisha)->toBe(100000000)
        ->and(glBalance('1312'))->toBe(-100000000);

    $approved = app(ApproveInvestmentTopUp::class)($this->president, $first);
    app(ApproveInvestmentTopUp::class)($this->president, $second);

    $shop = $this->shop->fresh();

    expect($approved->status)->toBe(InvestmentTopUpStatus::Approved)
        ->and($approved->journalEntry?->voucher_no)->toStartWith('PV-')
        ->and($approved->journalEntry?->entry_date->toDateString())->toBe('2026-08-10')
        ->and($shop->totalInvested()->poisha)->toBe(115000000)
        ->and($shop->bookValue()->poisha)->toBe(115000000)
        ->and($shop->principal_poisha->poisha)->toBe(100000000)
        ->and(glBalance('1312'))->toBe(-115000000)
        ->and(glBalance('1111'))->toBe(-5000000)
        ->and($shop->ledger()->orderBy('id')->pluck('kind')->all())
        ->toBe([InvestmentEntryKind::Disbursement, InvestmentEntryKind::TopUp, InvestmentEntryKind::TopUp]);

    $register = app(InvestmentRegister::class)->asOf(CarbonImmutable::parse('2026-08-31'));

    expect($register['rows'][0]['invested']->poisha)->toBe(110000000)
        ->and($register['groups'][0]['ledger']->poisha)->toBe(110000000);

    assertBooksTieOut();
});

it('needs a second person to approve, and the database refuses a self-approval', function (): void {
    $topUp = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData());

    expect(fn () => app(ApproveInvestmentTopUp::class)($this->accountant, $topUp))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::table('investment_top_ups')->whereKey($topUp->id)->update(['approved_by' => $this->accountant->id]))->toThrow(QueryException::class);
});

it('adds money only to an active investment', function (): void {
    $pending = app(RecordInvestment::class)($this->accountant, investmentData());

    expect(investmentRule(fn () => app(RecordInvestmentTopUp::class)($this->accountant, $pending, topUpData())))->toBe('investments.errors.top_up_not_active');

    $topUp = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData());
    app(CloseInvestment::class)($this->president, $this->shop, Money::ofTaka('1000000'), CarbonImmutable::parse('2026-09-12'), PaymentMethod::Bank);

    expect(investmentRule(fn () => app(ApproveInvestmentTopUp::class)($this->president, $topUp)))->toBe('investments.errors.top_up_not_active')
        ->and($topUp->fresh()?->status)->toBe(InvestmentTopUpStatus::Pending);
});

it('refuses a bad amount or date', function (array $overrides, string $rule): void {
    expect(investmentRule(fn () => app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData($overrides))))->toBe($rule);
})->with([
    'zero amount' => [['amount' => Money::zero()], 'investments.errors.amount_positive'],
    'before the investment' => [['invested_on' => '2026-07-09'], 'investments.errors.top_up_date'],
    'in the future' => [['invested_on' => '2026-09-16'], 'investments.errors.future_date'],
]);

it('refuses to approve more than the bank holds and posts nothing', function (): void {
    $topUp = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData(['amount' => Money::ofTaka('300000')]));

    expect(investmentRule(fn () => app(ApproveInvestmentTopUp::class)($this->president, $topUp)))->toBe('accounting.errors.insufficient_funds')
        ->and($topUp->fresh()?->status)->toBe(InvestmentTopUpStatus::Pending)
        ->and($this->shop->totalInvested()->poisha)->toBe(100000000);
});

it('is rejected with a reason or cancelled by its recorder, and then cannot be approved', function (): void {
    $rejected = app(RejectInvestmentTopUp::class)($this->president, app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData()), 'Committee did not agree');
    $mine = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData());

    expect(fn () => app(CancelInvestmentTopUp::class)($this->president, $mine))->toThrow(AuthorizationException::class);

    $cancelled = app(CancelInvestmentTopUp::class)($this->accountant, $mine);

    expect($rejected->status)->toBe(InvestmentTopUpStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Committee did not agree')
        ->and($cancelled->status)->toBe(InvestmentTopUpStatus::Cancelled)
        ->and(investmentRule(fn () => app(ApproveInvestmentTopUp::class)($this->president, $rejected)))->toBe('investments.errors.top_up_not_pending')
        ->and(investmentRule(fn () => app(RejectInvestmentTopUp::class)($this->president, $cancelled, 'Too late now')))->toBe('investments.errors.top_up_not_pending')
        ->and($this->shop->totalInvested()->poisha)->toBe(100000000);
});

it('records a resubmitted form once, and refuses the same key with a different amount', function (): void {
    $data = topUpData();

    $first = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, $data);
    $again = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, $data);

    expect($again->id)->toBe($first->id)
        ->and(InvestmentTopUp::query()->count())->toBe(1)
        ->and(investmentRule(fn () => app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, new InvestmentTopUpData(
            Money::ofTaka('5'), $data->fundedFrom, $data->investedOn, $data->idempotencyKey,
        ))))->toBe('investments.errors.idempotency_conflict');
});

it('waits in the president’s queue, not the recorder’s', function (): void {
    $topUp = app(RecordInvestmentTopUp::class)($this->accountant, $this->shop, topUpData());

    $forPresident = app(ApprovalQueue::class)->forUser($this->president);

    expect($forPresident->contains(fn ($item): bool => $item->record instanceof InvestmentTopUp && $item->record->is($topUp)))->toBeTrue()
        ->and(app(ApprovalQueue::class)->forUser($this->accountant)->contains(fn ($item): bool => $item->record instanceof InvestmentTopUp))->toBeFalse();
});

it('is recorded from the investment page and approved from its list', function (): void {
    $this->actingAs($this->accountant);

    Livewire::test(ViewInvestment::class, ['record' => $this->shop->getKey()])
        ->callAction('addTopUp', data: [
            'amount' => '100000',
            'invested_on' => '2026-08-10',
            'funded_from' => 'bank',
            'confirm_text' => $this->shop->investment_no,
        ])
        ->assertHasNoActionErrors();

    $topUp = InvestmentTopUp::query()->sole();

    expect($topUp->amount_poisha->poisha)->toBe(10000000);

    $this->actingAs($this->president);

    Livewire::test(TopUpsRelationManager::class, ['ownerRecord' => $this->shop, 'pageClass' => ViewInvestment::class])
        ->assertCanSeeTableRecords([$topUp])
        ->callAction(TestAction::make('approveTopUp')->table($topUp), data: ['confirm_text' => $this->shop->investment_no])
        ->assertHasNoActionErrors();

    expect($topUp->fresh()?->status)->toBe(InvestmentTopUpStatus::Approved)
        ->and(Investment::query()->findOrFail($this->shop->id)->totalInvested()->poisha)->toBe(110000000);
});

it('hides “Add investment” from people who cannot record investments', function (): void {
    $this->actingAs(userWithRole(Role::Auditor));

    Livewire::test(ViewInvestment::class, ['record' => $this->shop->getKey()])->assertActionHidden('addTopUp');
});
