<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Exits\Actions\ApproveExit;
use App\Domain\Exits\Actions\CancelExit;
use App\Domain\Exits\Actions\PayExit;
use App\Domain\Exits\Actions\RequestExit;
use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Exits\Services\ExitCalculator;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Models\YearEnd;
use App\Enums\Role;
use App\Filament\Resources\MemberExits\Pages\CreateMemberExit;
use App\Filament\Resources\MemberExits\Pages\ListMemberExits;
use App\Filament\Resources\MemberExits\Pages\ViewMemberExit;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;

/*
| Phase 12 (W9, BR-22): exit request → freeze → settlement to 2301 → payout; the member's
| sub-ledgers are zero afterwards.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->secretary = userWithRole(Role::Secretary);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    // ৳500/share deposit, ৳10 service charge, ৳100 registration fee (the helper defaults).
    approvedPlan('2026-07', '500');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function requestExit(Member $member, string $month, ExitReason $reason = ExitReason::Voluntary, string $fee = '0'): MemberExit
{
    return app(RequestExit::class)(userWithRole(Role::Secretary), $member, $reason, 'Moving to another district', YearMonth::parse($month), Money::ofTaka($fee));
}

function exitRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

function memberLedger(Member $member, string $code): int
{
    return app(ExitCalculator::class)->memberBalance($member->id, $code)->poisha;
}

it('settles savings less what is still owed, then pays it out and leaves every sub-ledger at zero', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '610'); // registration 100 + July 500 + 10
    generateMonth('2026-08');
    travelTo('2026-08-05');
    receivePayment($member, '510');
    generateMonth('2026-09'); // September stays unpaid: ৳500 deposit + ৳10 service charge

    $exit = requestExit($member, '2026-09', fee: '50');
    travelTo('2026-10-03');

    expect(app(ExitCalculator::class)->preview($member, YearMonth::of(2026, 9), Money::ofTaka('50'))->net()->poisha)->toBe(100000 - 1000 - 5000);

    $approved = app(ApproveExit::class)($this->president, $exit);

    expect($approved->status)->toBe(ExitStatus::Approved)
        ->and($approved->savings_poisha?->poisha)->toBe(100000)
        ->and($approved->receivables_poisha?->poisha)->toBe(1000)
        ->and($approved->net_poisha?->poisha)->toBe(94000)
        ->and($member->fresh()?->status)->toBe(MemberStatus::Exited)
        ->and(memberLedger($member, '2101'))->toBe(0)
        ->and(memberLedger($member, '2301'))->toBe(94000)
        ->and(app(AdvanceLedger::class)->balance($member->id)->isZero())->toBeTrue()
        ->and(Due::query()->where('member_id', $member->id)->where('month', '2026-09-01')->where('type', DueType::Deposit)->first()?->status)->toBe(DueStatus::Cancelled)
        ->and(glBalance('4131'))->toBe(5000);

    assertBooksTieOut();

    app(PayExit::class)($this->accountant, $approved, PaymentMethod::Cash);

    expect($approved->fresh()?->status)->toBe(ExitStatus::Paid)
        ->and(memberLedger($member, '2301'))->toBe(0)
        ->and($approved->payouts()->sole()->amount_poisha->poisha)->toBe(94000);

    assertBooksTieOut();
});

it('stops generating dues after the exit month once requested', function (): void {
    $member = onboard(1, '2026-07');
    $other = onboard(1, '2026-07');
    generateMonth('2026-07');

    requestExit($member, '2026-07');
    generateMonth('2026-08');

    expect(Due::query()->where('member_id', $member->id)->where('month', '2026-08-01')->exists())->toBeFalse()
        ->and(Due::query()->where('member_id', $other->id)->where('month', '2026-08-01')->exists())->toBeTrue();
});

it('releases months paid in advance after the exit month back into the settlement', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '2140'); // registration + July + ৳1,530 advance
    generateMonth('2026-08');        // August (৳510) settled from the advance

    expect(app(AdvanceLedger::class)->balance($member->id)->poisha)->toBe(102000);

    $exit = requestExit($member, '2026-07');
    travelTo('2026-08-20');
    $approved = app(ApproveExit::class)($this->president, $exit);

    // July savings ৳500 + August's ৳510 released + the ৳1,020 advance left.
    expect($approved->released_poisha?->poisha)->toBe(51000)
        ->and($approved->net_poisha?->poisha)->toBe(50000 + 51000 + 102000)
        ->and(Due::query()->where('member_id', $member->id)->where('month', '2026-08-01')->get()->every(fn (Due $due): bool => $due->status === DueStatus::Cancelled))->toBeTrue();

    assertBooksTieOut();
});

it('splits a deceased member’s settlement between the nominees by their shares', function (): void {
    $member = onboard(1, '2026-07', ['nominees' => [
        nominee(['name' => 'Ayesha', 'share_percent' => '66.67']),
        nominee(['name' => 'Rafi', 'relation_id' => relationId('son'), 'share_percent' => '33.33']),
    ]]);
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '610.01'); // 1 poisha of advance makes the split uneven

    $exit = requestExit($member, '2026-07', ExitReason::Deceased);
    travelTo('2026-08-02');
    app(PayExit::class)($this->accountant, app(ApproveExit::class)($this->president, $exit), PaymentMethod::Cash);

    $payouts = $exit->payouts()->orderBy('id')->get();

    expect($payouts->pluck('payee')->all())->toBe(['Ayesha', 'Rafi'])
        ->and($payouts->map(fn ($payout): int => $payout->amount_poisha->poisha)->all())->toBe([33336, 16665]) // 33,335.67 and 16,665.33: the spare poisha goes to the larger remainder
        ->and($payouts->sum(fn ($payout): int => $payout->amount_poisha->poisha))->toBe(50001);
});

it('refuses the exit while the member owes more than they hold', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07'); // ৳100 registration + ৳10 service owed, nothing paid

    $exit = requestExit($member, '2026-07');
    travelTo('2026-08-02');

    expect(exitRule(fn () => app(ApproveExit::class)($this->president, $exit)))->toBe('exits.errors.shortfall')
        ->and($member->fresh()?->status)->toBe(MemberStatus::Active)
        ->and($exit->fresh()?->status)->toBe(ExitStatus::Requested);
});

it('includes unpaid dividends in the settlement', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '610');

    $yearEnd = YearEnd::query()->create([
        'fiscal_year_id' => FiscalYear::query()->value('id'), 'status' => 'draft', 'net_profit_poisha' => Money::ofTaka('100'),
        'reserve_bps' => 1500, 'development_fund_bps' => 300, 'bad_debt_fund_bps' => 0, 'other_funds_bps' => 0, 'appropriation' => [],
        'dividend_pool_poisha' => Money::ofTaka('82'), 'fingerprint' => str_repeat('0', 64), 'prepared_by' => $this->accountant->id,
    ]);
    // A dividend declared to the member (posted through the normal JV path).
    app(PostJournal::class)($this->accountant, new JournalEntryData(
        VoucherType::Journal, CarbonImmutable::parse('2026-07-20'), 'Dividend declared', [
            JournalLineData::debit(account('3901'), Money::ofTaka('82')),
            JournalLineData::credit(account('2201'), Money::ofTaka('82'), $member->id),
        ],
    ));
    $yearEnd->dividendLines()->create(['member_id' => $member->id, 'share_months' => 12, 'amount_poisha' => Money::ofTaka('82'), 'status' => 'unpaid']);

    $exit = requestExit($member, '2026-07');
    travelTo('2026-08-02');
    $approved = app(ApproveExit::class)($this->president, $exit);

    expect($approved->dividends_poisha?->poisha)->toBe(8200)
        ->and($approved->net_poisha?->poisha)->toBe(50000 + 8200)
        ->and(memberLedger($member, '2201'))->toBe(0);
});

it('approves only after the exit month, by the president, never the requester', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '610');

    $exit = app(RequestExit::class)($this->president, $member, ExitReason::Voluntary, 'Leaving the area', YearMonth::of(2026, 7), Money::zero());

    expect(exitRule(fn () => app(ApproveExit::class)(userWithRole(Role::President), $exit)))->toBe('exits.errors.month_not_over');

    travelTo('2026-08-02');

    expect(fn () => app(ApproveExit::class)($this->president, $exit))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveExit::class)($this->accountant, $exit))->toThrow(AuthorizationException::class);

    expect(app(CancelExit::class)($this->secretary, $exit, 'Changed their mind')->status)->toBe(ExitStatus::Cancelled)
        ->and(requestExit($member, '2026-07')->status)->toBe(ExitStatus::Requested);
});

it('requests, approves and pays an exit from the screens', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '610');
    travelTo('2026-08-03');

    $this->actingAs($this->secretary);
    $this->get(ListMemberExits::getUrl())->assertOk();

    Livewire\Livewire::test(CreateMemberExit::class)
        ->fillForm(['member_id' => $member->id, 'reason_type' => 'voluntary', 'exit_month' => '2026-07', 'exit_fee' => '0', 'reason' => 'Moving abroad'])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    $exit = MemberExit::query()->sole();
    $this->get(ViewMemberExit::getUrl(['record' => $exit]))->assertOk();

    $this->actingAs($this->president);
    Livewire\Livewire::test(ViewMemberExit::class, ['record' => $exit->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => $member->member_no])
        ->assertHasNoActionErrors();

    $this->actingAs($this->accountant);
    Livewire\Livewire::test(ViewMemberExit::class, ['record' => $exit->getRouteKey()])
        ->callAction('pay', data: ['paid_from' => 'cash', 'confirm_text' => $member->member_no])
        ->assertHasNoActionErrors();

    expect($exit->fresh()?->status)->toBe(ExitStatus::Paid);
    assertBooksTieOut();
});
