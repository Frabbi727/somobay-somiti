<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateAccount;
use App\Domain\Accounting\Actions\DeleteAccount;
use App\Domain\Accounting\Actions\RestoreAccount;
use App\Domain\Accounting\Actions\SetAccountActive;
use App\Domain\Accounting\Actions\UpdateAccount;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
});

function accountData(array $overrides = []): AccountData
{
    return AccountData::fromArray([
        'code' => '5107',
        'name_en' => 'Printing',
        'name_bn' => 'ছাপা খরচ',
        'type' => 'expense',
        ...$overrides,
    ]);
}

it('seeds the standard chart of accounts once', function (): void {
    $this->seed(ChartOfAccountsSeeder::class);

    expect(Account::query()->count())->toBe(count(ChartOfAccountsSeeder::ACCOUNTS));

    $advance = Account::query()->where('code', '2111')->sole();

    expect($advance->type)->toBe(AccountType::Liability)
        ->and($advance->normal_balance)->toBe(NormalBalance::Credit)
        ->and($advance->is_control)->toBeTrue()
        ->and($advance->requires_member)->toBeTrue();

    foreach (Account::all() as $account) {
        expect($account->normal_balance)->toBe($account->type->normalBalance())
            ->and($account->code)->toStartWith($account->type->codePrefix());
    }
});

it('creates an account with the normal balance implied by its type', function (): void {
    $account = app(CreateAccount::class)($this->accountant, accountData());

    expect($account->code)->toBe('5107')
        ->and($account->normal_balance)->toBe(NormalBalance::Debit)
        ->and($account->is_active)->toBeTrue()
        ->and(Activity::query()->where('subject_id', $account->id)->where('subject_type', $account->getMorphClass())->sole()->causer_id)
        ->toBe($this->accountant->id);
});

it('rejects invalid or duplicate codes', function (array $overrides, string $key): void {
    expect(fn () => app(CreateAccount::class)($this->accountant, accountData($overrides)))
        ->toThrow(fn (DomainRuleViolation $e) => expect($e->translationKey)->toBe($key));
})->with([
    'too short' => [['code' => '510'], 'accounting.errors.code_format'],
    'leading zero' => [['code' => '0510'], 'accounting.errors.code_format'],
    'wrong prefix' => [['code' => '4106'], 'accounting.errors.code_prefix'],
    'duplicate' => [['code' => '5101'], 'accounting.errors.code_taken'],
    'missing bangla name' => [['name_bn' => ' '], 'accounting.errors.names_required'],
]);

it('does not reuse the code of a deleted account', function (): void {
    $account = app(CreateAccount::class)($this->accountant, accountData());
    app(DeleteAccount::class)($this->accountant, $account);

    expect(fn () => app(CreateAccount::class)($this->accountant, accountData()))
        ->toThrow(DomainRuleViolation::class, __('accounting.errors.code_taken', ['code' => '5107']));
});

it('lets only super admins and accountants manage accounts', function (Role $role): void {
    app(CreateAccount::class)(userWithRole($role), accountData());
})->throws(AuthorizationException::class)->with([Role::Cashier, Role::Auditor, Role::President, Role::Secretary, Role::Member]);

it('updates names and flags', function (): void {
    $account = app(CreateAccount::class)($this->accountant, accountData());

    $updated = app(UpdateAccount::class)($this->accountant, $account, accountData(['name_en' => 'Printing & Photocopy', 'is_control' => true]));

    expect($updated->name_en)->toBe('Printing & Photocopy')
        ->and($updated->is_control)->toBeTrue();
});

it('freezes code, type and member requirement once an account has postings', function (array $overrides, string $key): void {
    $cash = Account::query()->where('code', '1101')->sole();
    $income = Account::query()->where('code', '4111')->sole();
    insertRawJournal($cash, $income);

    $data = AccountData::fromArray([
        'code' => '1101', 'name_en' => 'Cash in Hand', 'name_bn' => 'হাতে নগদ', 'type' => 'asset',
        ...$overrides,
    ]);

    expect(fn () => app(UpdateAccount::class)($this->accountant, $cash, $data))
        ->toThrow(fn (DomainRuleViolation $e) => expect($e->translationKey)->toBe($key));
})->with([
    'code' => [['code' => '1102'], 'accounting.errors.identity_frozen'],
    'requires member' => [['requires_member' => true], 'accounting.errors.member_flag_frozen'],
]);

it('still allows renaming an account that has postings', function (): void {
    $cash = Account::query()->where('code', '1101')->sole();
    insertRawJournal($cash, Account::query()->where('code', '4111')->sole());

    $updated = app(UpdateAccount::class)($this->accountant, $cash, AccountData::fromArray([
        'code' => '1101', 'name_en' => 'Cash Box', 'name_bn' => 'ক্যাশ বাক্স', 'type' => 'asset',
    ]));

    expect($updated->name_en)->toBe('Cash Box');
});

it('cannot delete an account that has journal lines', function (): void {
    $cash = Account::query()->where('code', '1101')->sole();
    insertRawJournal($cash, Account::query()->where('code', '4111')->sole());

    expect(fn () => app(DeleteAccount::class)($this->accountant, $cash))
        ->toThrow(DomainRuleViolation::class, __('accounting.errors.account_has_lines', ['code' => '1101']))
        ->and($cash->fresh()?->trashed())->toBeFalse()
        ->and($this->accountant->can('delete', $cash))->toBeFalse();
});

it('blocks a hard delete of an account with lines at the database level', function (): void {
    $cash = Account::query()->where('code', '1101')->sole();
    insertRawJournal($cash, Account::query()->where('code', '4111')->sole());

    DB::table('accounts')->where('id', $cash->id)->delete();
})->throws(QueryException::class);

it('rejects one-sided or zero journal lines at the database level', function (int $debit, int $credit): void {
    $cash = Account::query()->where('code', '1101')->sole();
    $entryId = insertRawJournal($cash, Account::query()->where('code', '4111')->sole());

    DB::table('journal_lines')->insert([
        'journal_entry_id' => $entryId, 'line_no' => 3, 'account_id' => $cash->id,
        'debit_poisha' => $debit, 'credit_poisha' => $credit,
    ]);
})->throws(QueryException::class)->with([
    'both sides' => [100, 100],
    'zero' => [0, 0],
    'negative' => [-100, 0],
]);

it('soft-deletes and restores an unused account', function (): void {
    $account = app(CreateAccount::class)($this->accountant, accountData());

    expect($this->accountant->can('delete', $account))->toBeTrue();

    app(DeleteAccount::class)($this->accountant, $account);
    expect(Account::query()->find($account->id))->toBeNull()
        ->and(Account::withTrashed()->find($account->id))->not->toBeNull()
        ->and($this->accountant->can('forceDelete', $account))->toBeFalse();

    app(RestoreAccount::class)($this->accountant, $account);
    expect(Account::query()->find($account->id))->not->toBeNull();
});

it('deactivates and reactivates an account', function (): void {
    $cash = Account::query()->where('code', '1101')->sole();

    expect(app(SetAccountActive::class)($this->accountant, $cash, false)->is_active)->toBeFalse()
        ->and(app(SetAccountActive::class)($this->accountant, $cash, true)->is_active)->toBeTrue();
});
