<?php

declare(strict_types=1);

use App\Domain\Accounting\Statements\ColumnMap;
use App\Domain\Accounting\Statements\ParsedLine;
use App\Domain\Accounting\Statements\StatementCsvParser;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

/**
 * @return list<array{0: string, 1: int, 2: ?string, 3: ?int}>
 */
function parsed(string $csv, ?ColumnMap $map = null): array
{
    return array_map(
        fn (ParsedLine $line): array => [$line->date->toDateString(), $line->amount->poisha, $line->reference, $line->balance?->poisha],
        (new StatementCsvParser)->parse($csv, $map),
    );
}

function parseError(string $csv): ?string
{
    try {
        (new StatementCsvParser)->parse($csv);
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('reads a bank statement with separate withdrawal and deposit columns', function (): void {
    $csv = <<<'CSV'
        Txn Date,Particulars,Cheque No,Withdrawal,Deposit,Balance
        01/08/2026,Opening balance,,,,"10,000.00"
        05/08/2026,CASH DEP,DEP-4471,,"15,000.00","25,000.00"
        19/08/2026,CHQ PAID,000123,"14,000.00",,"11,000.00"
        CSV;

    expect(parsed($csv))->toBe([
        ['2026-08-05', 1500000, 'DEP-4471', 2500000],
        ['2026-08-19', -1400000, '000123', 1100000],
    ]);
});

it('reads a wallet export with a signed amount, semicolons, times and Bangla digits', function (): void {
    $csv = "\xEF\xBB\xBFতারিখ;বিবরণ;TrxID;পরিমাণ;ব্যালেন্স\n"
        ."২০২৬-০৮-০৩ ১৪:৩১;Cash in from member;BK7HX2Q9;৬০০.০০;৬০০.০০\n"
        ."2026-08-04 09:02:11;Cash out;BK7J0011;(916.65);-316.65\n";

    expect(parsed($csv))->toBe([
        ['2026-08-03', 60000, 'BK7HX2Q9', 60000],
        ['2026-08-04', -91665, 'BK7J0011', -31665],
    ]);
});

it('understands Dr/Cr suffixes, BDT prefixes and day-month-name dates', function (): void {
    $csv = "Date,Description,Amount\n05-Aug-2026,Charge,BDT 115.00 Dr\n06-Aug-2026,Interest,12.50 Cr\n";

    expect(parsed($csv))->toBe([
        ['2026-08-05', -11500, null, null],
        ['2026-08-06', 1250, null, null],
    ]);
});

it('prefers day-first dates unless only month-first fits every row', function (): void {
    expect(parsed("Date,Amount\n03/08/2026,100\n")[0][0])->toBe('2026-08-03')
        ->and(parsed("Date,Amount\n08/03/2026,100\n08/25/2026,50\n")[0][0])->toBe('2026-08-03');
});

it('uses the columns the user chose when the headings are unknown', function (): void {
    $csv = "Kobe,Ki,Koto\n2026-08-05,Deposit,500\n";

    expect(parseError($csv))->toBe('statements.errors.columns')
        ->and(parsed($csv, new ColumnMap(date: 'Kobe', description: 'Ki', amount: 'Koto')))->toBe([['2026-08-05', 50000, null, null]]);
});

it('stops on an unreadable amount or date, naming the line', function (string $csv, string $key): void {
    expect(parseError($csv))->toBe($key);
})->with([
    'amount' => ["Date,Amount\n2026-08-05,12.345\n", 'statements.errors.amount'],
    'date' => ["Date,Amount\n31/02/2026,100\n", 'statements.errors.date'],
    'empty' => ["Date,Amount\n", 'statements.errors.empty'],
]);
