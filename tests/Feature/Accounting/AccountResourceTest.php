<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Enums\Role;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Support\ChangeSummary;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

function formAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', 'content');
}

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
});

it('lists the chart of accounts for an auditor without write actions', function (): void {
    $this->actingAs(userWithRole(Role::Auditor));
    $cash = Account::query()->where('code', '1101')->sole();

    Livewire::test(ListAccounts::class)
        ->assertCanSeeTableRecords([$cash])
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($cash))
        ->assertActionHidden(TestAction::make('delete')->table($cash))
        ->assertActionHidden(TestAction::make('toggleActive')->table($cash));
});

it('renders the list in Bangla', function (): void {
    app()->setLocale('bn');
    $this->actingAs(userWithRole(Role::Accountant));

    $this->get(route('filament.admin.resources.accounts.index'))
        ->assertOk()
        ->assertSee('হিসাবের তালিকা')
        ->assertSee('১১০১');
});

it('creates an account through the page after confirmation', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));

    Livewire::test(CreateAccount::class)
        ->fillForm([
            'code' => '5106',
            'type' => 'expense',
            'name_en' => 'Printing',
            'name_bn' => 'ছাপা খরচ',
        ])
        ->mountAction(formAction('create'))
        ->assertActionMounted(formAction('create'))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('accounting.notifications.account_created', ['code' => '5106']));

    expect(Account::query()->where('code', '5106')->sole()->name_bn)->toBe('ছাপা খরচ');
});

it('shows form errors before opening the confirmation', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));

    Livewire::test(CreateAccount::class)
        ->fillForm(['code' => '51', 'type' => 'expense', 'name_en' => 'X', 'name_bn' => 'X'])
        ->mountAction(formAction('create'))
        ->assertHasFormErrors(['code']);

    expect(Account::query()->where('code', '51')->exists())->toBeFalse();
});

it('turns business-rule violations into a notification', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));

    Livewire::test(CreateAccount::class)
        ->fillForm(['code' => '5101', 'type' => 'expense', 'name_en' => 'Dup', 'name_bn' => 'ডুপ'])
        ->callAction(formAction('create'))
        ->assertNotified(__('accounting.errors.code_taken', ['code' => '5101']));

    expect(Account::query()->where('code', '5101')->sole()->name_en)->toBe('Office & Stationery');
});

it('edits an account after confirmation', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));
    $account = Account::query()->where('code', '5199')->sole();

    Livewire::test(EditAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['name_en' => 'Miscellaneous'])
        ->mountAction(formAction('save'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($account->fresh()?->name_en)->toBe('Miscellaneous');
});

it('requires typing the account code to delete it', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));
    $account = Account::query()->where('code', '5199')->sole();

    Livewire::test(ListAccounts::class)
        ->callAction(TestAction::make('delete')->table($account), data: ['confirm_text' => '5198'])
        ->assertHasActionErrors(['confirm_text']);

    expect($account->fresh()?->trashed())->toBeFalse();

    Livewire::test(ListAccounts::class)
        ->callAction(TestAction::make('delete')->table($account), data: ['confirm_text' => '5199'])
        ->assertHasNoActionErrors();

    expect(Account::withTrashed()->find($account->id)?->trashed())->toBeTrue();
});

it('hides delete for an account with postings', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));
    $cash = Account::query()->where('code', '1101')->sole();
    insertRawJournal($cash, Account::query()->where('code', '4111')->sole());

    Livewire::test(ViewAccount::class, ['record' => $cash->getRouteKey()])
        ->assertActionHidden('delete')
        ->assertActionVisible('toggleActive');
});

it('deactivates an account from the table', function (): void {
    $this->actingAs(userWithRole(Role::Accountant));
    $account = Account::query()->where('code', '5105')->sole();

    Livewire::test(ListAccounts::class)
        ->callAction(TestAction::make('toggleActive')->table($account))
        ->assertNotified(__('accounting.notifications.account_deactivated', ['code' => '5105']));

    expect($account->fresh()?->is_active)->toBeFalse();
});

it('summarises only the changed fields for the edit confirmation', function (): void {
    $account = Account::query()->where('code', '5199')->sole();

    $rows = ChangeSummary::rows(
        AccountForm::summaryLabels(),
        AccountForm::summaryValues($account),
        AccountForm::summaryValues([...$account->attributesToArray(), 'type' => 'expense', 'name_en' => 'Miscellaneous', 'is_control' => true]),
    );

    expect($rows)->toBe([
        ['label' => 'Name (English)', 'old' => 'Other Expenses', 'new' => 'Miscellaneous'],
        ['label' => 'Control account', 'old' => 'No', 'new' => 'Yes'],
    ]);
});

it('summarises every field for the create confirmation', function (): void {
    $rows = ChangeSummary::rows(AccountForm::summaryLabels(), [], AccountForm::summaryValues([
        'code' => '5106', 'type' => 'expense', 'name_en' => 'Printing', 'name_bn' => 'ছাপা খরচ',
    ]));

    expect(array_column($rows, 'new', 'label'))->toBe([
        'Code' => '5106',
        'Type' => 'Expense',
        'Name (Bangla)' => 'ছাপা খরচ',
        'Name (English)' => 'Printing',
        'Control account' => 'No',
        'Requires member' => 'No',
        'Description' => '—',
    ]);
});

it('renders the change summary view', function (): void {
    $html = ChangeSummary::view([['label' => 'Name', 'old' => 'Old', 'new' => 'New']])->render();

    expect($html)->toContain('Old')->toContain('New')->toContain(__('confirm.old'));
});
