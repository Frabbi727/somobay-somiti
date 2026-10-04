<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\SetAccountActive;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalHasher;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->fiscalYear = app(OpenFiscalYear::class)($this->accountant, 2026);
    $this->post = app(PostJournal::class);
});

function violationKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('posts a balanced entry with a voucher number, period and hash', function (): void {
    $entry = ($this->post)($this->accountant, simpleEntry('1101', '4111', '1,020.00', type: VoucherType::Receipt));

    expect($entry->voucher_no)->toBe('RV-2026-27-000001')
        ->and($entry->voucher_type)->toBe(VoucherType::Receipt)
        ->and($entry->fiscal_year_id)->toBe($this->fiscalYear->id)
        ->and((string) $entry->period->month)->toBe('2026-07')
        ->and($entry->posted_by)->toBe($this->accountant->id)
        ->and($entry->hash)->toHaveLength(64)
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->amount()->poisha)->toBe(102000);
});

it('numbers each voucher type separately and sequentially', function (): void {
    $numbers = [
        ($this->post)($this->accountant, simpleEntry('1101', '4111', '10', type: VoucherType::Receipt))->voucher_no,
        ($this->post)($this->accountant, simpleEntry('5101', '1101', '5', type: VoucherType::Payment))->voucher_no,
        ($this->post)($this->accountant, simpleEntry('1101', '4111', '10', type: VoucherType::Receipt))->voucher_no,
        ($this->post)($this->accountant, simpleEntry('1101', '4111', '10', '2027-06-30', VoucherType::Receipt))->voucher_no,
    ];

    expect($numbers)->toBe(['RV-2026-27-000001', 'PV-2026-27-000001', 'RV-2026-27-000002', 'RV-2026-27-000003']);
});

it('restarts numbering in a new fiscal year', function (): void {
    app(OpenFiscalYear::class)($this->accountant, 2027);

    expect(($this->post)($this->accountant, simpleEntry('1101', '4111', '10', '2027-07-01'))->voucher_no)->toBe('JV-2027-28-000001');
});

it('rejects unbalanced entries', function (): void {
    $data = new JournalEntryData(VoucherType::Journal, CarbonImmutable::parse('2026-07-15'), 'Unbalanced', [
        JournalLineData::debit(account('1101'), Money::ofTaka('100')),
        JournalLineData::credit(account('4111'), Money::ofTaka('99.99')),
    ]);

    expect(violationKey(fn () => ($this->post)($this->accountant, $data)))->toBe('journal.errors.unbalanced')
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(DB::table('voucher_sequences')->value('last_number'))->toBeNull();
});

it('rejects malformed lines', function (Closure $lines, string $key): void {
    $data = new JournalEntryData(VoucherType::Journal, CarbonImmutable::parse('2026-07-15'), 'Bad', $lines());

    expect(violationKey(fn () => ($this->post)($this->accountant, $data)))->toBe($key);
})->with([
    'single line' => [fn () => [JournalLineData::debit(account('1101'), Money::ofTaka('1'))], 'journal.errors.too_few_lines'],
    'zero line' => [fn () => [
        JournalLineData::debit(account('1101'), Money::zero()),
        JournalLineData::credit(account('4111'), Money::zero()),
    ], 'journal.errors.one_sided'],
    'both sides on one line' => [fn () => [
        new JournalLineData(account('1101')->id, Money::ofTaka('5'), Money::ofTaka('5')),
        JournalLineData::credit(account('4111'), Money::ofTaka('0.01')),
    ], 'journal.errors.one_sided'],
    'negative amount' => [fn () => [
        JournalLineData::debit(account('1101'), Money::ofTaka('-5')),
        JournalLineData::credit(account('4111'), Money::ofTaka('-5')),
    ], 'journal.errors.one_sided'],
    'unknown account' => [fn () => [
        JournalLineData::debit(999999, Money::ofTaka('5')),
        JournalLineData::credit(account('4111'), Money::ofTaka('5')),
    ], 'journal.errors.unknown_account'],
    'member missing on member account' => [fn () => [
        JournalLineData::debit(account('1101'), Money::ofTaka('5')),
        JournalLineData::credit(account('2101'), Money::ofTaka('5')),
    ], 'journal.errors.member_required'],
    'member on non-member account' => [fn () => [
        JournalLineData::debit(account('1101'), Money::ofTaka('5'), memberId: Member::factory()->create()->id),
        JournalLineData::credit(account('4111'), Money::ofTaka('5')),
    ], 'journal.errors.member_not_allowed'],
]);

it('accepts member lines on member accounts', function (): void {
    $member = Member::factory()->create();

    $entry = ($this->post)($this->accountant, new JournalEntryData(VoucherType::Receipt, CarbonImmutable::parse('2026-07-15'), 'Deposit', [
        JournalLineData::debit(account('1101'), Money::ofTaka('500')),
        JournalLineData::credit(account('2101'), Money::ofTaka('500'), memberId: $member->id),
    ]));

    expect($entry->lines->last()?->member_id)->toBe($member->id);
});

it('rejects a member that does not exist', function (): void {
    $data = new JournalEntryData(VoucherType::Receipt, CarbonImmutable::parse('2026-07-15'), 'Deposit', [
        JournalLineData::debit(account('1101'), Money::ofTaka('500')),
        JournalLineData::credit(account('2101'), Money::ofTaka('500'), memberId: 999999),
    ]);

    expect(violationKey(fn () => ($this->post)($this->accountant, $data)))->toBe('journal.errors.unknown_member');
});

it('rejects postings to inactive accounts', function (): void {
    app(SetAccountActive::class)($this->accountant, account('5199'), false);

    expect(violationKey(fn () => ($this->post)($this->accountant, simpleEntry('5199', '1101', '10'))))->toBe('journal.errors.inactive_account');
});

it('rejects dates without an open fiscal year or in a locked month', function (): void {
    expect(violationKey(fn () => ($this->post)($this->accountant, simpleEntry('1101', '4111', '10', '2026-06-30'))))->toBe('journal.errors.no_fiscal_year');

    app(LockPeriod::class)($this->accountant, $this->fiscalYear->periods()->firstOrFail());
    expect(violationKey(fn () => ($this->post)($this->accountant, simpleEntry('1101', '4111', '10', '2026-07-31'))))->toBe('journal.errors.period_locked')
        ->and(($this->post)($this->accountant, simpleEntry('1101', '3101', '10', '2026-08-01'))->voucher_no)->toBe('JV-2026-27-000001');

    app(CloseFiscalYear::class)(userWithRole(Role::President), $this->fiscalYear);
    expect(violationKey(fn () => ($this->post)($this->accountant, simpleEntry('1101', '4111', '10', '2026-09-01'))))->toBe('accounting.errors.fiscal_year_closed');
});

it('chains hashes so every stored entry can be re-verified', function (): void {
    $hasher = app(JournalHasher::class);

    foreach (['10', '20.50', '30.05'] as $taka) {
        ($this->post)($this->accountant, simpleEntry('1101', '4111', $taka));
    }

    $previous = null;

    foreach (JournalEntry::query()->with('lines')->orderBy('id')->get() as $entry) {
        expect($hasher->hashOf($entry, $previous))->toBe($entry->hash);
        $previous = $entry->hash;
    }

    expect(DB::table('voucher_sequences')->value('last_hash'))->toBe($previous);
});
