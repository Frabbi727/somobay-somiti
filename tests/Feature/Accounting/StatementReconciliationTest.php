<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\IgnoreStatementLine;
use App\Domain\Accounting\Actions\ImportStatement;
use App\Domain\Accounting\Actions\MatchStatementLine;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Actions\UnmatchStatementLine;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Accounting\Statements\StatementReconciliation;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\StatementImports\Pages\CreateStatementImport;
use App\Filament\Resources\StatementImports\Pages\ListStatementImports;
use App\Filament\Resources\StatementImports\Pages\ViewStatementImport;
use App\Filament\Resources\StatementImports\RelationManagers\LinesRelationManager;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Phase 8 (P8.S3): bank/wallet statement import and matching against the books.
*/

beforeEach(function (): void {
    Storage::fake('local');
    travelTo('2026-08-03');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '600', ['service_charge_per_share_poisha' => Money::zero(), 'registration_fee_per_share_poisha' => Money::zero()]);

    $this->rahim = onboard(1, '2026-08');
    $this->karim = onboard(1, '2026-08');
    receivePayment($this->rahim, '600', 'bkash', 'BK1111AAAA');
    receivePayment($this->karim, '600', 'bkash', 'BK2222BBBB');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function importCsv(string $csv, string $name = 'bkash-aug.csv'): StatementImport
{
    $path = 'statements/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);

    return app(ImportStatement::class)(userWithRole(Role::Accountant), PaymentMethod::Bkash, $path, $name);
}

function statementRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

const AUGUST_BKASH = <<<'CSV'
    Date,Description,TrxID,Amount,Balance
    2026-08-03,Cash in,BK2222BBBB,600.00,600.00
    2026-08-03,Cash in,BK1111AAAA,600.00,1200.00
    2026-08-04,Monthly fee,BKFEE0001,-20.00,1180.00
    CSV;

it('imports a statement and matches by TrxID where amounts alone are ambiguous', function (): void {
    $import = importCsv(AUGUST_BKASH);

    $lines = $import->lines()->with('journalLine.entry.source')->orderBy('line_no')->get();

    expect($import->lines_count)->toBe(3)
        ->and($import->period_from?->toDateString())->toBe('2026-08-03')
        ->and($import->period_to?->toDateString())->toBe('2026-08-04')
        ->and($import->closing_balance_poisha?->poisha)->toBe(118000)
        ->and($lines->pluck('status')->all())->toBe([StatementLineStatus::Matched, StatementLineStatus::Matched, StatementLineStatus::Unmatched])
        ->and($lines[0]->journalLine?->entry->source?->getAttribute('trx_id'))->toBe('BK2222BBBB')
        ->and($lines[1]->journalLine?->entry->source?->getAttribute('trx_id'))->toBe('BK1111AAAA');

    $summary = app(StatementReconciliation::class)->summary($import);

    expect($summary['book_balance']->poisha)->toBe(120000)
        ->and($summary['difference']?->poisha)->toBe(-2000)
        ->and($summary['unmatched_count'])->toBe(1)
        ->and($summary['book_only'])->toHaveCount(0);
});

it('leaves equal amounts without references for a person to match', function (): void {
    $import = importCsv("Date,Amount\n2026-08-03,600\n2026-08-03,600\n");

    expect($import->countWithStatus(StatementLineStatus::Unmatched))->toBe(2);

    $first = $import->lines()->orderBy('line_no')->firstOrFail();
    $journalLine = JournalLine::query()->where('debit_poisha', 60000)->where('account_id', account('1121')->id)->orderBy('id')->firstOrFail();

    app(MatchStatementLine::class)($this->accountant, $first, $journalLine);

    expect($first->fresh()?->status)->toBe(StatementLineStatus::Matched)
        ->and(statementRule(fn () => app(MatchStatementLine::class)($this->accountant, $import->lines()->where('status', 'unmatched')->firstOrFail(), $journalLine)))
        ->toBe('statements.errors.already_matched');
});

it('matches the fee once it is booked, refusing a wrong amount', function (): void {
    $import = importCsv(AUGUST_BKASH);
    $fee = $import->lines()->where('status', StatementLineStatus::Unmatched)->sole();

    $expense = app(ApproveExpense::class)($this->accountant, app(RecordExpense::class)(userWithRole(Role::Cashier), ExpenseData::fromForm([
        'account_id' => account('5104')->id, 'paid_from' => 'bkash', 'amount' => Money::ofTaka('20'),
        'spent_on' => '2026-08-03', 'description' => 'bKash monthly fee', 'reference' => 'BKFEE0001',
    ])));
    $feeLine = JournalLine::query()->where('journal_entry_id', $expense->journal_entry_id)->where('credit_poisha', 2000)->sole();
    $paymentLine = JournalLine::query()->where('debit_poisha', 60000)->where('account_id', account('1121')->id)->firstOrFail();

    expect(statementRule(fn () => app(MatchStatementLine::class)($this->accountant, $fee, $paymentLine)))->toBe('statements.errors.mismatch');

    app(MatchStatementLine::class)($this->accountant, $fee, $feeLine);

    expect(app(StatementReconciliation::class)->summary($import)['difference']?->poisha)->toBe(0);
});

it('refuses the same file twice and skips lines already imported from an overlapping statement', function (): void {
    importCsv(AUGUST_BKASH);

    expect(statementRule(fn () => importCsv(AUGUST_BKASH, 'again.csv')))->toBe('statements.errors.already_imported');

    $overlap = importCsv(AUGUST_BKASH."\n2026-08-05,Cash in,BK3333CCCC,300.00,1480.00\n", 'bkash-aug-full.csv');

    expect($overlap->lines_count)->toBe(1)
        ->and($overlap->duplicates_skipped)->toBe(3)
        ->and(StatementLine::query()->count())->toBe(4);
});

it('ignores a line with a reason and can undo it', function (): void {
    $import = importCsv(AUGUST_BKASH);
    $fee = $import->lines()->where('status', StatementLineStatus::Unmatched)->sole();

    expect(statementRule(fn () => app(IgnoreStatementLine::class)($this->accountant, $fee, 'no')))->toBe('journal.errors.reason_required');

    expect(app(IgnoreStatementLine::class)($this->accountant, $fee, 'bKash refunded this fee')->status)->toBe(StatementLineStatus::Ignored)
        ->and(app(UnmatchStatementLine::class)($this->accountant, $fee)->status)->toBe(StatementLineStatus::Unmatched);
});

it('lets only the accountant or president import statements, never for cash', function (): void {
    $path = 'statements/x.csv';
    Storage::disk('local')->put($path, AUGUST_BKASH);

    expect(fn () => app(ImportStatement::class)(userWithRole(Role::Cashier), PaymentMethod::Bkash, $path, 'x.csv'))->toThrow(AuthorizationException::class)
        ->and(statementRule(fn () => app(ImportStatement::class)($this->accountant, PaymentMethod::Cash, $path, 'x.csv')))->toBe('statements.errors.no_cash');
});

it('flags a match that no longer agrees with its book entry', function (): void {
    $import = importCsv(AUGUST_BKASH);
    $line = $import->lines()->where('status', StatementLineStatus::Matched)->firstOrFail();

    DB::table('statement_lines')->where('id', $line->id)->update(['amount_poisha' => 59900]);

    expect(app(InvariantChecker::class)->findings())
        ->toContain("Statement {$import->id}, line {$line->line_no}, is matched to a book entry with a different account or amount");
});

it('imports a statement from the screen and works its lines', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    $this->actingAs($this->accountant);

    $this->get(ListStatementImports::getUrl())->assertOk();

    Livewire\Livewire::test(CreateStatementImport::class)
        ->fillForm([
            'method' => 'bkash',
            'file_path' => UploadedFile::fake()->createWithContent('bkash-aug.csv', AUGUST_BKASH),
        ])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors()
        ->assertNotified(__('statements.notifications.imported', ['lines' => '৩', 'matched' => '২'], 'bn'));

    $import = StatementImport::query()->sole();
    $fee = $import->lines()->where('status', StatementLineStatus::Unmatched)->sole();

    $this->get(ViewStatementImport::getUrl(['record' => $import]))
        ->assertOk()
        ->assertSee(__('statements.summary.heading'));

    Livewire\Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $import,
        'pageClass' => ViewStatementImport::class,
    ])
        ->assertCanSeeTableRecords($import->lines()->get())
        ->callAction(TestAction::make('ignore')->table($fee), data: ['reason' => 'Fee refunded by bKash'])
        ->assertHasNoActionErrors();

    expect($fee->fresh()?->status)->toBe(StatementLineStatus::Ignored)
        ->and($import->file_path)->toStartWith('statements/')
        ->and($import->filename)->toBe('bkash-aug.csv');
});
