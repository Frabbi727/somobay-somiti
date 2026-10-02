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

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
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
        ->and(fn () => app(PostJournalDraft::class)($this->accountant, $draft))->toThrow(DomainRuleViolation::class);

    $draft = $save($this->accountant, $draft, draftData([['1111', '5000', null], ['1101', null, '5000']], 'CV'));
    $entry = app(PostJournalDraft::class)($this->accountant, $draft);

    expect($entry->voucher_no)->toBe('CV-2026-27-000001')
        ->and($entry->source_type)->toBe($draft->getMorphClass())
        ->and($entry->source_id)->toBe($draft->id)
        ->and($draft->fresh()?->journal_entry_id)->toBe($entry->id);
});

it('locks a draft once posted', function (): void {
    $draft = app(SaveJournalDraft::class)($this->accountant, null, draftData([['1111', '10', null], ['1101', null, '10']]));
    app(PostJournalDraft::class)($this->accountant, $draft);
    $draft->refresh();

    expect(fn () => app(PostJournalDraft::class)($this->accountant, $draft))->toThrow(AuthorizationException::class)
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
