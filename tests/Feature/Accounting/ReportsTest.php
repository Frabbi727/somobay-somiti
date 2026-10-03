<?php

declare(strict_types=1);

use App\Domain\Accounting\Contracts\SubledgerSource;
use App\Domain\Accounting\Reports\TrialBalanceRow;
use App\Domain\Accounting\Services\LedgerQuery;
use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Accounting\Services\TrialBalance;
use App\Reports\LedgerDocument;
use App\Reports\TrialBalanceDocument;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoBooksSeeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    $this->seed(DemoBooksSeeder::class);
    $this->asOf = CarbonImmutable::parse('2026-09-30');
});

/**
 * @return array<string, array{0: int, 1: int}> code => [debit balance, credit balance] in poisha
 */
function trialBalanceByCode(CarbonImmutable $asOf): array
{
    $balances = [];

    foreach (app(TrialBalance::class)->asOf($asOf)->balanceRows() as $row) {
        $balances[$row->account->code] = [$row->debitBalance()->poisha, $row->creditBalance()->poisha];
    }

    return $balances;
}

it('balances to the poisha on the demo books', function (): void {
    $report = app(TrialBalance::class)->asOf($this->asOf);

    expect($report->isBalanced())->toBeTrue()
        ->and($report->totalDebit()->poisha)->toBe(984240)
        ->and($report->totalCredit()->poisha)->toBe(984240)
        ->and(trialBalanceByCode($this->asOf))->toBe([
            '1101' => [168125, 0],
            '1111' => [306240, 0],
            '1121' => [163000, 0],
            '1301' => [300000, 0],
            '2101' => [0, 900000],
            '4101' => [0, 60000],
            '4111' => [0, 18000],
            '4201' => [0, 6240],
            '5101' => [36150, 0],
            '5105' => [10725, 0],
        ]);
});

it('leaves out accounts whose postings net to zero but still sums them', function (): void {
    $report = app(TrialBalance::class)->asOf($this->asOf);
    $rent = collect($report->rows)->first(fn (TrialBalanceRow $row): bool => $row->account->code === '5103');

    expect($rent?->totalDebit->poisha)->toBe(80000)
        ->and($rent?->totalCredit->poisha)->toBe(80000)
        ->and(array_key_exists('5103', trialBalanceByCode($this->asOf)))->toBeFalse();
});

it('respects the as-of date', function (): void {
    $july = app(TrialBalance::class)->asOf(CarbonImmutable::parse('2026-07-31'));

    expect($july->isBalanced())->toBeTrue()
        ->and(trialBalanceByCode(CarbonImmutable::parse('2026-07-31'))['2101'])->toBe([0, 300000])
        ->and(app(TrialBalance::class)->asOf(CarbonImmutable::parse('2026-06-30'))->rows)->toBe([]);
});

it('computes running balances in SQL that match a cumulative sum', function (): void {
    $report = app(LedgerQuery::class)->forAccount(account('1101'), CarbonImmutable::parse('2026-08-01'), $this->asOf);

    expect($report->opening->poisha)->toBe(89375);

    $running = $report->opening;

    foreach ($report->rows as $row) {
        $running = $running->plus($row->debit)->minus($row->credit);
        expect($row->balance->equals($running))->toBeTrue();
    }

    expect($report->closing()->poisha)->toBe(168125)
        ->and($report->closing()->equals($running))->toBeTrue();
});

it('filters a ledger to one member', function (): void {
    $report = app(LedgerQuery::class)->forAccount(account('2101'), CarbonImmutable::parse('2026-07-01'), $this->asOf, memberId: 3);

    expect($report->rows)->toHaveCount(3)
        ->and($report->totalCredit()->poisha)->toBe(450000)
        ->and($report->closing()->poisha)->toBe(-450000);
});

it('reconciles every control account on the demo books', function (): void {
    $checks = app(Reconciliation::class)->controlVsSubledger($this->asOf);

    expect(collect($checks)->every(fn ($check): bool => $check->isReconciled()))->toBeTrue()
        ->and(collect($checks)->firstWhere(fn ($check): bool => $check->account->code === '2101')?->generalLedger->poisha)->toBe(-900000);
});

it('flags a control account posting that has no member', function (): void {
    $entryId = (int) DB::table('journal_entries')->value('id');

    // Bypass PostJournal, as a buggy import might; the deferred balance check would still
    // catch the imbalance at commit, which this test never reaches.
    DB::table('journal_lines')->insert([
        'journal_entry_id' => $entryId, 'line_no' => 99, 'account_id' => account('2101')->id,
        'debit_poisha' => 0, 'credit_poisha' => 1234,
    ]);

    $check = collect(app(Reconciliation::class)->controlVsSubledger($this->asOf))
        ->firstWhere(fn ($check): bool => $check->account->code === '2101');

    expect($check?->isReconciled())->toBeFalse()
        ->and($check?->difference()->poisha)->toBe(-1234);
});

it('uses a registered sub-ledger source instead of member-tagged lines', function (): void {
    app()->bind('test.advance-ledger', fn (): SubledgerSource => new class implements SubledgerSource
    {
        public function accountCode(): string
        {
            return '2111';
        }

        public function label(): string
        {
            return 'Advance ledger';
        }

        public function balanceAsOf(CarbonImmutable $date): Money
        {
            return Money::ofTaka('-50');
        }
    });
    app()->tag(['test.advance-ledger'], 'somiti.subledgers');
    app()->forgetInstance(Reconciliation::class);

    $check = collect(app(Reconciliation::class)->controlVsSubledger($this->asOf))
        ->firstWhere(fn ($check): bool => $check->account->code === '2111');

    expect($check?->source)->toBe('Advance ledger')
        ->and($check?->isReconciled())->toBeFalse()
        ->and($check?->difference()->poisha)->toBe(5000);
});

it('renders the trial balance as a PDF with the Bangla font embedded', function (): void {
    app()->setLocale('bn');

    $pdf = app(TrialBalanceDocument::class)->pdf($this->asOf);

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain('HindSiliguri');
});

it('renders a ledger PDF in landscape', function (): void {
    $pdf = app(LedgerDocument::class)->pdf(account('1101'), CarbonImmutable::parse('2026-07-01'), $this->asOf, null);

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toMatch('#/MediaBox \[0 0 841\.89\d* 595\.28\d*\]#');
});

it('writes money to Excel as numbers that total like the app', function (): void {
    app()->setLocale('en');
    $path = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
    file_put_contents($path, app(TrialBalanceDocument::class)->excel($this->asOf));

    $sheet = IOFactory::load($path)->getActiveSheet();
    $rows = $sheet->toArray(null, false, false);
    unlink($path);

    $total = collect($rows)->first(fn (array $row): bool => $row[0] === 'Total');
    $cash = collect($rows)->first(fn (array $row): bool => $row[0] === '1101');

    expect($rows[3])->toBe(['Code', 'Account', 'Type', 'Debit', 'Credit'])
        ->and($cash[3])->toBe(1681.25)
        ->and((string) $total[3])->toBe('9842.4')
        ->and((string) $total[4])->toBe('9842.4');
});
