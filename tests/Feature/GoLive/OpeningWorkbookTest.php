<?php

declare(strict_types=1);

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Services\OpeningWorkbookReader;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

it('builds a template with the three data sheets, text columns and bilingual labels', function (): void {
    $path = openingWorkbookFile([]);
    $book = IOFactory::load($path);
    $members = $book->getSheetByName('Members');

    expect($book->getSheetNames())->toBe(['Members', 'Society', 'Investments', 'Instructions'])
        ->and($members->getCell('A1')->getValue())->toBe('member_no')
        ->and($members->getStyle('D3')->getNumberFormat()->getFormatCode())->toBe('@')
        ->and((string) $members->getCell('A2')->getValue())->toContain('/')
        ->and($book->getSheetByName('Investments')->getCell('B1')->getValue())->toBe('name');
});

it('reads rows by header name, skips the label row and blank rows, and keeps text exactly', function (): void {
    $path = openingWorkbookFile(['members' => [
        ['member_no' => 'M-0042', 'mobile' => '01712345678', 'savings' => '১,০০৫.২৫'],
        [],
        ['member_no' => 'M-0043', 'savings' => 1005.25],
    ]]);

    $book = app(OpeningWorkbookReader::class)->read($path);

    expect($book->members)->toHaveCount(2)
        ->and($book->members[0])->toMatchArray(['_row' => '3', 'member_no' => 'M-0042', 'mobile' => '01712345678', 'savings' => '১,০০৫.২৫', 'name_bn' => ''])
        ->and($book->members[1])->toMatchArray(['_row' => '5', 'savings' => '1005.25']);
});

it('passes a number Excel stored with extra decimals through unrounded so validation can refuse it', function (): void {
    $path = openingWorkbookFile(['members' => [['member_no' => 'M-0001', 'savings' => 1005.2500001]]]);

    expect(app(OpeningWorkbookReader::class)->read($path)->members[0]['savings'])->toBe('1005.2500001');
});

it('round-trips through toArray/fromArray', function (): void {
    $book = app(OpeningWorkbookReader::class)->read(openingWorkbookFile(['society' => [['account_code' => '1101', 'amount' => '500']]]));

    expect(OpeningWorkbook::fromArray($book->toArray()))->toEqual($book);
});

it('refuses a file that is not our workbook', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, 'not a spreadsheet');

    expect(fn () => app(OpeningWorkbookReader::class)->read($path))->toThrow(DomainRuleViolation::class);

    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Sheet1');
    $other = tempnam(sys_get_temp_dir(), 'y').'.xlsx';
    (new Xlsx($book))->save($other);

    expect(fn () => app(OpeningWorkbookReader::class)->read($other))->toThrow(DomainRuleViolation::class, __('golive.errors.missing_sheet', ['sheet' => 'Members']));
});
