<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\ApproveFundTransfer;
use App\Domain\Accounting\Actions\CancelFundTransfer;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Actions\RecordFundTransfer;
use App\Domain\Accounting\Actions\RejectFundTransfer;
use App\Domain\Accounting\Actions\ReverseFundTransfer;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Data\FundTransferData;
use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Reporting\CashMovements;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\FundTransfers\Pages\CreateFundTransfer;
use App\Filament\Resources\FundTransfers\Pages\ListFundTransfers;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Phase 8 (P8.S2): fund transfers between cash, bank and wallets, posted as contra vouchers.
*/

beforeEach(function (): void {
    travelTo('2026-08-20');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->cashier = userWithRole(Role::Cashier);
    app(OpenFiscalYear::class)($this->accountant, 2026);

    // ৳20,000 in hand, ৳1,000 in bKash.
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '3101', '20000', '2026-08-01'));
    app(PostJournal::class)($this->accountant, simpleEntry('1121', '3101', '1000', '2026-08-01'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function transferData(array $overrides = []): FundTransferData
{
    return FundTransferData::fromForm([
        'from_method' => 'cash',
        'to_method' => 'bank',
        'amount' => Money::ofTaka('15000'),
        'charge' => Money::zero(),
        'transferred_on' => '2026-08-19',
        'reference' => 'DEP-4471',
        ...$overrides,
    ]);
}

function transferRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('deposits cash in the bank with a contra voucher', function (): void {
    $transfer = app(RecordFundTransfer::class)($this->cashier, transferData());

    expect($transfer->status)->toBe(TransferStatus::Pending)
        ->and($transfer->transfer_no)->toMatch('/^T-\d{5}$/');

    $approved = app(ApproveFundTransfer::class)($this->accountant, $transfer);

    expect($approved->journalEntry?->voucher_no)->toStartWith('CV-2026-27-')
        ->and(glBalance('1101'))->toBe(-500000)
        ->and(glBalance('1111'))->toBe(-1500000);

    assertBooksTieOut();
});

it('books a wallet cash-out fee to bank & wallet charges', function (): void {
    $transfer = app(RecordFundTransfer::class)($this->cashier, transferData([
        'from_method' => 'bkash', 'to_method' => 'cash', 'amount' => Money::ofTaka('900'), 'charge' => Money::ofTaka('16.65'),
    ]));

    app(ApproveFundTransfer::class)($this->accountant, $transfer);

    expect(glBalance('1121'))->toBe(-(100000 - 90000 - 1665))
        ->and(glBalance('1101'))->toBe(-(2000000 + 90000))
        ->and(glBalance('5104'))->toBe(-1665);

    assertBooksTieOut();
});

it('refuses a transfer the source cannot cover, charge included', function (): void {
    $transfer = app(RecordFundTransfer::class)($this->cashier, transferData([
        'from_method' => 'bkash', 'to_method' => 'cash', 'amount' => Money::ofTaka('990'), 'charge' => Money::ofTaka('18.50'),
    ]));

    expect(transferRule(fn () => app(ApproveFundTransfer::class)($this->accountant, $transfer)))->toBe('accounting.errors.insufficient_funds')
        ->and($transfer->fresh()?->status)->toBe(TransferStatus::Pending);
});

it('rejects invalid transfers', function (array $overrides, string $key): void {
    expect(transferRule(fn () => app(RecordFundTransfer::class)($this->cashier, transferData($overrides))))->toBe($key);
})->with([
    'same account' => [['to_method' => 'cash'], 'transfers.errors.same_account'],
    'zero amount' => [['amount' => Money::zero()], 'transfers.errors.amount_positive'],
    'future date' => [['transferred_on' => '2026-08-30'], 'transfers.errors.future_date'],
]);

it('keeps maker and checker apart', function (): void {
    $transfer = app(RecordFundTransfer::class)($this->accountant, transferData());

    app(ApproveFundTransfer::class)($this->accountant, $transfer);
})->throws(AuthorizationException::class);

it('rejects with a reason and lets the maker cancel', function (): void {
    $first = app(RecordFundTransfer::class)($this->cashier, transferData());
    $second = app(RecordFundTransfer::class)($this->cashier, transferData());

    expect(app(RejectFundTransfer::class)($this->accountant, $first, 'Slip not attached')->status)->toBe(TransferStatus::Rejected)
        ->and(app(CancelFundTransfer::class)($this->cashier, $second)->status)->toBe(TransferStatus::Cancelled);
});

it('reverses a transfer only while the destination still holds the money', function (): void {
    $transfer = app(ApproveFundTransfer::class)($this->accountant, app(RecordFundTransfer::class)($this->cashier, transferData()));

    // Spend most of the bank balance.
    app(ApproveExpense::class)(userWithRole(Role::President), app(RecordExpense::class)($this->cashier, ExpenseData::fromForm([
        'account_id' => account('5103')->id, 'paid_from' => 'bank', 'amount' => Money::ofTaka('14000'),
        'spent_on' => '2026-08-19', 'description' => 'Office rent',
    ])));

    expect(transferRule(fn () => app(ReverseFundTransfer::class)($this->accountant, $transfer, 'Deposit was recorded twice')))
        ->toBe('accounting.errors.insufficient_funds');

    app(PostJournal::class)($this->accountant, simpleEntry('1111', '3101', '14000', '2026-08-19'));
    $reversed = app(ReverseFundTransfer::class)($this->accountant, $transfer, 'Deposit was recorded twice');

    expect($reversed->status)->toBe(TransferStatus::Reversed)
        ->and(glBalance('1101'))->toBe(-2000000);

    assertBooksTieOut();
});

it('flags a transfer whose voucher no longer matches it', function (): void {
    $transfer = app(ApproveFundTransfer::class)($this->accountant, app(RecordFundTransfer::class)($this->cashier, transferData()));

    DB::table('fund_transfers')->where('id', $transfer->id)->update(['charge_poisha' => 500]);

    expect(app(InvariantChecker::class)->findings())->toContain("Transfer {$transfer->transfer_no}: voucher does not move 1500000 (+500 charge) as recorded");
});

it('records and approves a bank deposit from the Fund transfers screen', function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');

    $this->actingAs($this->cashier);
    $this->get(ListFundTransfers::getUrl())->assertOk();

    Livewire::test(CreateFundTransfer::class)
        ->fillForm(['from_method' => 'cash', 'to_method' => 'cash', 'amount' => '500'])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasFormErrors(['to_method']);

    Livewire::test(CreateFundTransfer::class)
        ->fillForm(['from_method' => 'cash', 'to_method' => 'bank', 'amount' => '12,000', 'charge' => '0', 'transferred_on' => '2026-08-20', 'reference' => 'DEP-1'])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    $transfer = FundTransfer::query()->sole();
    $this->actingAs($this->accountant);

    Livewire::test(ListFundTransfers::class)
        ->callAction(TestAction::make('approve')->table($transfer), data: ['confirm_text' => $transfer->transfer_no])
        ->assertHasNoActionErrors();

    expect($transfer->fresh()?->status)->toBe(TransferStatus::Approved)
        ->and(glBalance('1111'))->toBe(-1200000);
});

it('shows only the fee in receipts & payments, since money moving between own accounts is neither', function (): void {
    app(ApproveFundTransfer::class)($this->accountant, app(RecordFundTransfer::class)($this->cashier, transferData()));
    app(ApproveFundTransfer::class)($this->accountant, app(RecordFundTransfer::class)($this->cashier, transferData([
        'from_method' => 'bkash', 'to_method' => 'cash', 'amount' => Money::ofTaka('900'), 'charge' => Money::ofTaka('16.65'),
    ])));

    $report = app(CashMovements::class)->receiptsAndPayments(CarbonImmutable::parse('2026-08-02'), CarbonImmutable::parse('2026-08-31'));

    expect($report['total_receipts']->poisha)->toBe(0)
        ->and(array_map(fn (array $row): array => [$row['account']->code, $row['amount']->poisha], $report['payments']))->toBe([['5104', 1665]]);
});
