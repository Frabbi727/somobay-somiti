<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\PostJournalDraft;
use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\CancelInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RejectInvestment;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\Investments\Pages\CreateInvestment;
use App\Filament\Resources\Investments\Pages\ListInvestments;
use App\Filament\Resources\Investments\Pages\ViewInvestment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Phase 10 (P10.S1): investment register, disbursement PV, register ↔ 13xx tie, s.33 warnings.
*/

beforeEach(function (): void {
    travelTo('2026-09-15');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);

    // ৳1,00,000 in the bank; ৳20,000 accumulated surplus.
    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3101', '100000', '2026-07-05'));
    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3901', '20000', '2026-07-05'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function investmentData(array $overrides = []): InvestmentData
{
    return InvestmentData::fromForm([
        'type' => 'fixed_deposit',
        'institution' => 'Sonali Bank',
        'instrument_no' => 'FDR-1001',
        'principal' => Money::ofTaka('50000'),
        'funded_from' => 'bank',
        'invested_on' => '2026-09-10',
        'matures_on' => '2027-09-10',
        'expected_rate' => '8.5',
        ...$overrides,
    ]);
}

function investmentRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('records, and on the president’s approval invests by payment voucher into its 13xx account', function (): void {
    $investment = app(RecordInvestment::class)($this->accountant, investmentData());

    expect($investment->status)->toBe(InvestmentStatus::Pending)
        ->and($investment->investment_no)->toMatch('/^INV-\d{4}$/')
        ->and($investment->expected_rate_bps)->toBe(850);

    $approved = app(ApproveInvestment::class)($this->president, $investment);

    expect($approved->status)->toBe(InvestmentStatus::Active)
        ->and($approved->journalEntry?->voucher_no)->toStartWith('PV-')
        ->and($approved->bookValue()->poisha)->toBe(5000000)
        ->and(glBalance('1301'))->toBe(-5000000)
        ->and(glBalance('1111'))->toBe(-7000000)
        ->and($approved->limit_warnings)->toBeNull();

    assertBooksTieOut();
});

it('keeps each 13xx account equal to its register', function (): void {
    app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, investmentData()));
    app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, investmentData(['type' => 'savings_certificate', 'principal' => Money::ofTaka('10000')])));

    $checks = collect(app(Reconciliation::class)->controlVsSubledger(CarbonImmutable::parse('2026-09-30')))
        ->filter(fn ($check): bool => str_starts_with($check->account->code, '13'));

    expect($checks)->toHaveCount(6)
        ->and($checks->every(fn ($check): bool => $check->isReconciled()))->toBeTrue()
        ->and($checks->firstWhere(fn ($check): bool => $check->account->code === '1302')?->subledger->poisha)->toBe(1000000);
});

it('refuses manual vouchers to accounts kept by a register', function (string $code): void {
    $memberId = null;

    if ($code === '2111') {
        approvedPlan('2026-07', '500');
        $memberId = onboard()->id;
    }

    $draft = app(SaveJournalDraft::class)($this->accountant, null, JournalDraftData::fromForm([
        'voucher_type' => 'JV',
        'entry_date' => '2026-09-12',
        'narration' => 'Manual write-down',
        'lines' => [
            ['account_id' => account('5199')->id, 'debit' => Money::ofTaka('100'), 'credit' => null],
            ['account_id' => account($code)->id, 'debit' => null, 'credit' => Money::ofTaka('100'), 'member_id' => $memberId],
        ],
    ]));

    expect(investmentRule(fn () => app(PostJournalDraft::class)(userWithRole(Role::Accountant), $draft)))->toBe('journal.errors.register_account');
})->with(['1301', '2111']);

it('warns, without blocking, when company securities exceed 10% of the surplus', function (): void {
    $investment = app(RecordInvestment::class)($this->accountant, investmentData(['type' => 'company_securities', 'principal' => Money::ofTaka('2500')]));

    $approved = app(ApproveInvestment::class)($this->president, $investment);

    expect($approved->status)->toBe(InvestmentStatus::Active)
        ->and($approved->limit_warnings)->toContain('৳ ২,৫০০.০০')
        ->and($approved->limit_warnings)->toContain('৳ ২,০০০.০০');
});

it('needs the president, never the maker', function (Role $role): void {
    $investment = app(RecordInvestment::class)($this->accountant, investmentData());

    app(ApproveInvestment::class)($role === Role::Accountant ? $this->accountant : userWithRole($role), $investment);
})->with([Role::Accountant, Role::Secretary, Role::SuperAdmin])->throws(AuthorizationException::class);

it('refuses an investment the paying account cannot cover', function (): void {
    $investment = app(RecordInvestment::class)($this->accountant, investmentData(['funded_from' => 'cash']));

    expect(investmentRule(fn () => app(ApproveInvestment::class)($this->president, $investment)))->toBe('accounting.errors.insufficient_funds');
});

it('needs a passed investment resolution where configured', function (): void {
    config(['somiti.require_resolution_for' => ['investment']]);
    $investment = app(RecordInvestment::class)($this->accountant, investmentData());

    expect(investmentRule(fn () => app(ApproveInvestment::class)($this->president, $investment)))->toBe('governance.errors.resolution_required');
});

it('validates the details and lets the maker cancel or the president reject', function (): void {
    expect(investmentRule(fn () => app(RecordInvestment::class)($this->accountant, investmentData(['matures_on' => '2026-09-01']))))->toBe('investments.errors.maturity')
        ->and(investmentRule(fn () => app(RecordInvestment::class)($this->accountant, investmentData(['institution' => '']))))->toBe('investments.errors.institution_required');

    $first = app(RecordInvestment::class)($this->accountant, investmentData());
    $second = app(RecordInvestment::class)($this->accountant, investmentData());

    expect(app(CancelInvestment::class)($this->accountant, $first)->status)->toBe(InvestmentStatus::Cancelled)
        ->and(app(RejectInvestment::class)($this->president, $second, 'Rate too low this month')->status)->toBe(InvestmentStatus::Rejected)
        ->and(Investment::query()->where('status', 'active')->count())->toBe(0);
});

it('keeps the register append-only', function (): void {
    $investment = app(ApproveInvestment::class)($this->president, app(RecordInvestment::class)($this->accountant, investmentData()));

    expect(fn () => DB::table('investment_ledger_entries')->where('investment_id', $investment->id)->update(['delta_poisha' => 1]))
        ->toThrow(QueryException::class);
});

it('records and approves an investment from the screens', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');
    $this->actingAs($this->accountant);

    $this->get(ListInvestments::getUrl())->assertOk();

    Livewire\Livewire::test(CreateInvestment::class)
        ->fillForm([
            'type' => 'savings_certificate', 'institution' => 'Bangladesh Bank (Sanchayapatra)', 'instrument_no' => 'SC-77',
            'principal' => '25,000', 'funded_from' => 'bank', 'invested_on' => '2026-09-14', 'matures_on' => '2031-09-14', 'expected_rate' => '11.28',
        ])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    $investment = Investment::query()->sole();
    $this->actingAs($this->president);

    Livewire\Livewire::test(ListInvestments::class)
        ->callAction(TestAction::make('approve')->table($investment), data: ['confirm_text' => $investment->investment_no])
        ->assertHasNoActionErrors();

    expect($investment->fresh()?->status)->toBe(InvestmentStatus::Active)
        ->and(glBalance('1302'))->toBe(-2500000);

    $this->get(ViewInvestment::getUrl(['record' => $investment]))->assertOk()->assertSee('SC-77');
});
