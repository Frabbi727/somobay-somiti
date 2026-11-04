<?php

declare(strict_types=1);

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Data\ValidatedOpening;
use App\Domain\GoLive\Services\OpeningBalanceSheet;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(fn () => $this->seed(ChartOfAccountsSeeder::class));

function opening(array $members, array $society = [], array $investments = []): ValidatedOpening
{
    $result = app(OpeningImportValidator::class)->validate(new OpeningWorkbook($members, $society, $investments), YearMonth::parse('2026-07'));
    expect($result->errors)->toBe([]);

    return $result;
}

it('balances with the difference in accumulated surplus', function (): void {
    $result = opening(
        [memberRow(['member_no' => 'M-0001', 'savings' => '10000', 'advance' => '1000']), memberRow(['member_no' => 'M-0002', 'savings' => '5000'])],
        [['_row' => '3', 'account_code' => '1101', 'amount' => '2000'], ['_row' => '4', 'account_code' => '3201', 'amount' => '500']],
        [['_row' => '3', 'type' => 'fixed_deposit', 'name' => 'Sonali FDR', 'institution' => 'Sonali', 'instrument_no' => '', 'principal' => '15000', 'invested_on' => '2025-01-01', 'matures_on' => '', 'expected_rate_percent' => '', 'funded_from' => 'bank']],
    );

    $summary = app(OpeningBalanceSheet::class)->summary($result);
    $lines = app(OpeningBalanceSheet::class)->journalLines($result, ['M-0001' => 11, 'M-0002' => 12]);
    $debit = Money::sum(array_map(fn ($l) => $l->debit, $lines));
    $credit = Money::sum(array_map(fn ($l) => $l->credit, $lines));

    // assets 2,000 + 15,000 = 17,000; owed 10,000 + 1,000 + 5,000 + 500 = 16,500 → surplus 500 credit
    expect($summary['surplus_poisha'])->toBe(50000)
        ->and($debit->equals($credit))->toBeTrue()
        ->and($debit->poisha)->toBe(1700000)
        ->and(collect($lines)->where('memberId', 11)->count())->toBe(2)
        ->and(collect($lines)->firstWhere('memo', 'Sonali FDR'))->not->toBeNull()
        ->and($summary)->toMatchArray(['members' => 2, 'shares' => 4, 'savings_poisha' => 1500000, 'advance_poisha' => 100000, 'investments' => 1])
        ->and(collect($summary['lines'])->firstWhere('code', '2101'))->toBe(['code' => '2101', 'debit_poisha' => 0, 'credit_poisha' => 1500000]);
});

it('debits accumulated surplus when the society owes more than it holds, and omits it when equal', function (): void {
    $short = opening([memberRow(['member_no' => 'M-0001', 'savings' => '1000'])], [['_row' => '3', 'account_code' => '1101', 'amount' => '400']]);
    $even = opening([memberRow(['member_no' => 'M-0001', 'savings' => '1000'])], [['_row' => '3', 'account_code' => '1101', 'amount' => '1000']]);
    $sheet = app(OpeningBalanceSheet::class);

    expect($sheet->summary($short)['surplus_poisha'])->toBe(-60000)
        ->and(collect($sheet->journalLines($short, ['M-0001' => 1]))->last()->debit->poisha)->toBe(60000)
        ->and(collect($sheet->journalLines($even, ['M-0001' => 1])))->toHaveCount(2);
});

it('always balances for random sheets', function (): void {
    foreach (range(1, 50) as $seed) {
        mt_srand($seed);
        $members = [];

        foreach (range(1, mt_rand(1, 8)) as $i) {
            $members[] = memberRow(['member_no' => sprintf('M-%04d', $i), 'savings' => mt_rand(0, 99999).'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT), 'advance' => (string) mt_rand(0, 500)]);
        }

        $result = opening($members, [['_row' => '3', 'account_code' => '1111', 'amount' => (string) mt_rand(0, 900000)]]);
        $lines = app(OpeningBalanceSheet::class)->journalLines($result, collect($members)->mapWithKeys(fn ($m, $i) => [$m['member_no'] => $i + 1])->all());

        expect(Money::sum(array_map(fn ($l) => $l->debit, $lines))->equals(Money::sum(array_map(fn ($l) => $l->credit, $lines))))->toBeTrue();
    }
});
