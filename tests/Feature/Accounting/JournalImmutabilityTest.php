<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Support\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($accountant, 2026);
    $this->entry = app(PostJournal::class)($accountant, simpleEntry('1101', '4111', '100'));
});

it('refuses model updates and deletes of posted entries and lines', function (Closure $change): void {
    $change($this->entry);
})->throws(ImmutableRecord::class)->with([
    'entry update' => [fn ($entry) => $entry->forceFill(['narration' => 'changed'])->save()],
    'entry delete' => [fn ($entry) => $entry->delete()],
    'line update' => [fn ($entry) => $entry->lines->first()->forceFill(['debit_poisha' => Money::ofPoisha(1)])->save()],
    'line delete' => [fn ($entry) => $entry->lines->first()->delete()],
]);

it('refuses raw SQL updates and deletes through database triggers', function (string $sql): void {
    DB::statement($sql, [$this->entry->id]);
})->throws(QueryException::class, 'immutable')->with([
    'entry update' => ["UPDATE journal_entries SET narration = 'x' WHERE id = ?"],
    'entry delete' => ['DELETE FROM journal_entries WHERE id = ?'],
    'line update' => ['UPDATE journal_lines SET debit_poisha = debit_poisha + 1 WHERE journal_entry_id = ?'],
    'line delete' => ['DELETE FROM journal_lines WHERE journal_entry_id = ?'],
]);

it('rejects an unbalanced entry at commit through the deferred constraint trigger', function (): void {
    DB::table('journal_lines')->insert([
        'journal_entry_id' => $this->entry->id,
        'line_no' => 3,
        'account_id' => account('5101')->id,
        'debit_poisha' => 1,
        'credit_poisha' => 0,
    ]);

    // RefreshDatabase never commits, so make the deferred check run now.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
})->throws(QueryException::class, 'not balanced');

it('rejects an entry with no lines at commit', function (): void {
    $entryId = DB::table('journal_entries')->insertGetId([
        'fiscal_year_id' => $this->entry->fiscal_year_id,
        'period_id' => $this->entry->period_id,
        'voucher_type' => 'JV',
        'voucher_no' => 'JV-RAW-1',
        'entry_date' => '2026-07-15',
        'narration' => 'raw',
        'posted_by' => $this->entry->posted_by,
        'posted_at' => now(),
        'created_at' => now(),
    ]);

    expect($entryId)->toBeInt();

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
})->throws(QueryException::class, 'not balanced');
