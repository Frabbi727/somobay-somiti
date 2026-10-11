<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\DeleteJournalDraft;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournalDraft;
use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->checker = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
});

/**
 * @param  list<array{0: string, 1: string|null, 2: string|null}>  $lines  [account code, debit taka, credit taka]
 */
function draftData(array $lines, string $type = 'JV'): JournalDraftData
{
    return JournalDraftData::fromForm([
        'voucher_type' => $type,
        'entry_date' => '2026-07-20',
        'narration' => 'Bank deposit of cash',
        'lines' => array_map(fn (array $line): array => [
            'account_id' => account($line[0])->id,
            'debit' => $line[1] === null ? null : Money::ofTaka($line[1]),
            'credit' => $line[2] === null ? null : Money::ofTaka($line[2]),
        ], $lines),
    ]);
}

it('saves an unbalanced draft and posts it once balanced', function (): void {
    $save = app(SaveJournalDraft::class);

    $draft = $save($this->accountant, null, draftData([['1111', '5000', null], ['1101', null, '4000']], 'CV'));

    expect($draft->totalDebit()->poisha)->toBe(500000)
        ->and($draft->totalCredit()->poisha)->toBe(400000)
        ->and(fn () => app(PostJournalDraft::class)($this->checker, $draft))->toThrow(DomainRuleViolation::class);

    $draft = $save($this->accountant, $draft, draftData([['1111', '5000', null], ['1101', null, '5000']], 'CV'));
    $entry = app(PostJournalDraft::class)($this->checker, $draft);

    expect($entry->voucher_no)->toBe('CV-2026-27-000001')
        ->and($entry->source_type)->toBe($draft->getMorphClass())
        ->and($entry->source_id)->toBe($draft->id)
        ->and($draft->fresh()?->journal_entry_id)->toBe($entry->id);
});

it('locks a draft once posted', function (): void {
    $draft = app(SaveJournalDraft::class)($this->accountant, null, draftData([['1111', '10', null], ['1101', null, '10']]));
    app(PostJournalDraft::class)($this->checker, $draft);
    $draft->refresh();

    expect(fn () => app(PostJournalDraft::class)($this->checker, $draft))->toThrow(DomainRuleViolation::class)
        ->and(fn () => app(SaveJournalDraft::class)($this->accountant, $draft, draftData([['1111', '1', null], ['1101', null, '1']])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteJournalDraft::class)($this->accountant, $draft))->toThrow(DomainRuleViolation::class)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('soft-deletes an unposted draft', function (): void {
    $draft = app(SaveJournalDraft::class)($this->accountant, null, draftData([['1111', '10', null]]));

    app(DeleteJournalDraft::class)($this->accountant, $draft);

    expect(JournalDraft::query()->find($draft->id))->toBeNull()
        ->and(JournalDraft::withTrashed()->find($draft->id))->not->toBeNull();
});

it('only allows manual voucher types in drafts', function (): void {
    app(SaveJournalDraft::class)($this->accountant, null, draftData([['1101', '10', null], ['4111', null, '10']], 'RV'));
})->throws(DomainRuleViolation::class);

it('keeps cashiers and auditors from preparing or posting vouchers', function (Role $role): void {
    app(SaveJournalDraft::class)(userWithRole($role), null, draftData([['1111', '10', null], ['1101', null, '10']]));
})->throws(AuthorizationException::class)->with([Role::Cashier, Role::Auditor, Role::SuperAdmin]);

it('ignores empty repeater rows and blank amounts', function (): void {
    $data = JournalDraftData::fromForm([
        'voucher_type' => 'JV',
        'entry_date' => '2026-07-20',
        'narration' => 'x',
        'lines' => [
            ['account_id' => account('1101')->id, 'debit' => '12.50', 'credit' => '', 'member_id' => '', 'memo' => ' '],
            ['account_id' => null, 'debit' => null, 'credit' => null],
        ],
    ]);

    expect($data->lines)->toBe([
        ['account_id' => account('1101')->id, 'member_id' => null, 'debit_poisha' => 1250, 'credit_poisha' => 0, 'memo' => null],
    ]);
});

it('is posted by someone other than whoever prepared or last edited it', function (): void {
    $draft = app(SaveJournalDraft::class)($this->accountant, null, draftData([['1111', '10', null], ['1101', null, '10']]));

    expect($this->accountant->can('post', $draft))->toBeFalse()
        ->and(fn () => app(PostJournalDraft::class)($this->accountant, $draft))
        ->toThrow(DomainRuleViolation::class, __('journal.errors.post_own_draft'));

    // The president edits it, so now the accountant checks and the president may not post.
    $draft = app(SaveJournalDraft::class)($this->checker, $draft, draftData([['1111', '20', null], ['1101', null, '20']]));

    expect($this->checker->can('post', $draft))->toBeFalse()
        ->and(fn () => app(PostJournalDraft::class)($this->checker, $draft))
        ->toThrow(DomainRuleViolation::class, __('journal.errors.post_own_draft'));

    $third = userWithRole(Role::Accountant);
    $entry = app(PostJournalDraft::class)($third, $draft);

    expect($entry->posted_by)->toBe($third->id)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('refuses a self-posted draft in the database too', function (): void {
    $draft = app(SaveJournalDraft::class)($this->accountant, null, draftData([['1111', '10', null], ['1101', null, '10']]));
    $period = DB::table('periods')->orderBy('id')->first();

    DB::transaction(function () use ($draft, $period): void {
        $entryId = (int) DB::table('journal_entries')->insertGetId([
            'fiscal_year_id' => $period->fiscal_year_id,
            'period_id' => $period->id,
            'voucher_type' => 'JV',
            'voucher_no' => 'JV-TEST-SELF',
            'entry_date' => '2026-07-20',
            'narration' => 'self posted',
            'posted_by' => $this->accountant->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $entryId, 'line_no' => 1, 'account_id' => account('1111')->id, 'debit_poisha' => 1000, 'credit_poisha' => 0],
            ['journal_entry_id' => $entryId, 'line_no' => 2, 'account_id' => account('1101')->id, 'debit_poisha' => 0, 'credit_poisha' => 1000],
        ]);

        DB::table('journal_drafts')->where('id', $draft->id)->update(['journal_entry_id' => $entryId]);
    });
})->throws(QueryException::class, 'must be posted by someone other than its author');
