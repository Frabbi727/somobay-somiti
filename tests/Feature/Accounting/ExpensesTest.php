<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\CancelExpense;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Actions\RejectExpense;
use App\Domain\Accounting\Actions\ReverseExpense;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Phase 8 (P8.S1): expenses — maker-checker, president above the threshold, PV posting, no overdraft.
*/

beforeEach(function (): void {
    travelTo('2026-08-20');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->cashier = userWithRole(Role::Cashier);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);

    // ৳20,000 in hand from share capital.
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '3101', '20000', '2026-08-01'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function expenseData(array $overrides = []): ExpenseData
{
    return ExpenseData::fromForm([
        'account_id' => account('5101')->id,
        'paid_from' => 'cash',
        'amount' => Money::ofTaka('1500'),
        'spent_on' => '2026-08-18',
        'payee' => 'Rahman Stationers',
        'description' => 'Receipt books and register',
        ...$overrides,
    ]);
}

function expenseRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('records a pending expense with a number, and posts a PV on approval', function (): void {
    $expense = app(RecordExpense::class)($this->cashier, expenseData());

    expect($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->expense_no)->toMatch('/^E-\d{5}$/')
        ->and($expense->journal_entry_id)->toBeNull()
        ->and(glBalance('5101'))->toBe(0);

    $approved = app(ApproveExpense::class)($this->accountant, $expense);
    $entry = $approved->journalEntry;

    expect($approved->status)->toBe(ExpenseStatus::Approved)
        ->and($approved->approved_by)->toBe($this->accountant->id)
        ->and($entry?->voucher_no)->toStartWith('PV-2026-27-')
        ->and($entry?->entry_date->toDateString())->toBe('2026-08-18')
        ->and(glBalance('5101'))->toBe(-150000) // debit balance
        ->and(glBalance('1101'))->toBe(-1850000);

    assertBooksTieOut();
});

it('returns the first expense when the same form is submitted twice', function (): void {
    $data = expenseData(['idempotency_key' => '6f1e2d1c-1111-4c3e-9a51-2b6a0a3d9e10']);

    $first = app(RecordExpense::class)($this->cashier, $data);
    $second = app(RecordExpense::class)($this->cashier, $data);

    expect($second->id)->toBe($first->id)
        ->and(Expense::query()->count())->toBe(1);
});

it('rejects invalid expenses', function (array $overrides, string $key): void {
    expect(expenseRule(fn () => app(RecordExpense::class)($this->cashier, expenseData($overrides))))->toBe($key);
})->with([
    'not an expense account' => [fn () => ['account_id' => account('1101')->id], 'expenses.errors.expense_account'],
    'zero amount' => [['amount' => Money::zero()], 'expenses.errors.amount_positive'],
    'no purpose' => [['description' => ''], 'expenses.errors.description_required'],
    'future date' => [['spent_on' => '2026-08-25'], 'expenses.errors.future_date'],
]);

it('never lets the maker approve their own expense, nor the super admin or a cashier', function (Role $role): void {
    $maker = userWithRole(Role::Accountant);
    $expense = app(RecordExpense::class)($maker, expenseData());
    $approver = $role === Role::Accountant ? $maker : userWithRole($role);

    app(ApproveExpense::class)($approver, $expense);
})->with([Role::Accountant, Role::SuperAdmin, Role::Cashier])->throws(AuthorizationException::class);

it('needs the president at or above the threshold', function (): void {
    config(['somiti.expense_president_threshold_poisha' => 1_000_000]);
    $large = app(RecordExpense::class)($this->cashier, expenseData(['amount' => Money::ofTaka('10000')]));

    expect(fn () => app(ApproveExpense::class)($this->accountant, $large))->toThrow(AuthorizationException::class);

    expect(app(ApproveExpense::class)($this->president, $large)->status)->toBe(ExpenseStatus::Approved);
});

it('refuses to pay out more than the paying account holds', function (): void {
    $expense = app(RecordExpense::class)($this->cashier, expenseData(['paid_from' => 'bkash']));

    expect(expenseRule(fn () => app(ApproveExpense::class)($this->accountant, $expense)))->toBe('accounting.errors.insufficient_funds')
        ->and($expense->fresh()?->status)->toBe(ExpenseStatus::Pending)
        ->and(JournalEntry::query()->where('voucher_type', 'PV')->count())->toBe(0);
});

it('rejects with a reason, and lets only the maker cancel', function (): void {
    $expense = app(RecordExpense::class)($this->cashier, expenseData());

    expect(expenseRule(fn () => app(RejectExpense::class)($this->accountant, $expense, 'no')))->toBe('journal.errors.reason_required');
    expect(fn () => app(CancelExpense::class)($this->accountant, $expense))->toThrow(AuthorizationException::class);

    expect(app(RejectExpense::class)($this->accountant, $expense, 'Bill is not signed')->status)->toBe(ExpenseStatus::Rejected);

    $other = app(RecordExpense::class)($this->cashier, expenseData());
    expect(app(CancelExpense::class)($this->cashier, $other)->status)->toBe(ExpenseStatus::Cancelled);
});

it('reverses an approved expense through the expense, not the voucher screen', function (): void {
    $expense = app(ApproveExpense::class)($this->accountant, app(RecordExpense::class)($this->cashier, expenseData()));
    $voucher = JournalEntry::query()->findOrFail($expense->journal_entry_id);

    expect($this->accountant->can('reverse', $voucher))->toBeFalse();
    expect(fn () => app(ReverseJournal::class)($this->accountant, $voucher, 'Wrong voucher'))->toThrow(AuthorizationException::class);

    $reversed = app(ReverseExpense::class)($this->accountant, $expense, 'Shop refunded the money');

    expect($reversed->status)->toBe(ExpenseStatus::Reversed)
        ->and($reversed->reversal_journal_entry_id)->not->toBeNull()
        ->and(glBalance('5101'))->toBe(0)
        ->and(glBalance('1101'))->toBe(-2000000);

    assertBooksTieOut();
});

it('flags an expense whose voucher no longer matches it', function (): void {
    $expense = app(ApproveExpense::class)($this->accountant, app(RecordExpense::class)($this->cashier, expenseData()));

    DB::table('expenses')->where('id', $expense->id)->update(['amount_poisha' => 160000]);

    expect(app(InvariantChecker::class)->findings())->toContain("Expense {$expense->expense_no}: voucher does not post 160000 from the paying account to the expense account");
});

it('records and approves an expense from the Expenses screen', function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');

    $this->actingAs($this->cashier);
    $this->get(ListExpenses::getUrl())->assertOk();

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'account_id' => account('5103')->id,
            'paid_from' => 'cash',
            'amount' => '2,400.50',
            'spent_on' => '2026-08-19',
            'description' => 'August office rent',
        ])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    $expense = Expense::query()->sole();
    expect($expense->amount_poisha->poisha)->toBe(240050);

    $this->actingAs($this->accountant);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$expense])
        ->callAction(TestAction::make('approve')->table($expense), data: ['confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(ListExpenses::class)
        ->callAction(TestAction::make('approve')->table($expense), data: ['confirm_text' => $expense->expense_no])
        ->assertHasNoActionErrors();

    expect($expense->fresh()?->status)->toBe(ExpenseStatus::Approved);
});

it('shows the approve button only to someone allowed to approve', function (): void {
    Filament::setCurrentPanel('admin');
    $expense = app(RecordExpense::class)($this->cashier, expenseData());

    $this->actingAs($this->cashier);
    Livewire::test(ListExpenses::class)->assertActionHidden(TestAction::make('approve')->table($expense));

    $this->actingAs(User::query()->findOrFail($this->accountant->id));
    Livewire::test(ListExpenses::class)->assertActionVisible(TestAction::make('approve')->table($expense));
});
