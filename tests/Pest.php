<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature, invariant and concurrency tests boot the application against the
| PostgreSQL test database. Unit and arch tests run without the framework.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Invariants');

pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A user holding the given role (roles are seeded on demand).
 */
function userWithRole(Role $role): User
{
    test()->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

/**
 * Inserts a minimal journal entry with one debit and one credit line straight into the
 * database. Only for tests that need "an account with postings" before PostJournal exists.
 */
function insertRawJournal(Account $debit, Account $credit, int $poisha = 10000): int
{
    $poster = User::factory()->create();
    $fiscalYear = app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    $period = $fiscalYear->periods()->firstOrFail();

    $entryId = (int) DB::table('journal_entries')->insertGetId([
        'fiscal_year_id' => $fiscalYear->id,
        'period_id' => $period->id,
        'voucher_type' => 'JV',
        'voucher_no' => 'JV-TEST-'.Str::random(6),
        'entry_date' => '2026-07-15',
        'narration' => 'test',
        'posted_by' => $poster->id,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $entryId, 'line_no' => 1, 'account_id' => $debit->id, 'debit_poisha' => $poisha, 'credit_poisha' => 0],
        ['journal_entry_id' => $entryId, 'line_no' => 2, 'account_id' => $credit->id, 'debit_poisha' => 0, 'credit_poisha' => $poisha],
    ]);

    return $entryId;
}

function account(string $code): Account
{
    return Account::query()->where('code', $code)->sole();
}

/**
 * A two-line entry: debit one account, credit another.
 */
function simpleEntry(string $debitCode, string $creditCode, string $taka, string $date = '2026-07-15', VoucherType $type = VoucherType::Journal): JournalEntryData
{
    $amount = Money::ofTaka($taka);

    return new JournalEntryData(
        type: $type,
        entryDate: CarbonImmutable::parse($date, 'Asia/Dhaka'),
        narration: "Test {$debitCode}/{$creditCode}",
        lines: [
            JournalLineData::debit(account($debitCode), $amount),
            JournalLineData::credit(account($creditCode), $amount),
        ],
    );
}
