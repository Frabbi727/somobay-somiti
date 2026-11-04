<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The go-live workbook: one sheet each for members, society balances and investments, plus
 * instructions. Row 1 holds the column keys the reader matches on, row 2 bilingual labels.
 */
final class OpeningTemplate
{
    public const array SHEETS = ['members' => 'Members', 'society' => 'Society', 'investments' => 'Investments'];

    public const array COLUMNS = [
        'members' => ['member_no', 'name_bn', 'name_en', 'mobile', 'nid', 'joined_on', 'shares', 'savings', 'advance', 'arrears_deposit', 'arrears_fees',
            'nominee1_name', 'nominee1_relation', 'nominee1_percent', 'nominee2_name', 'nominee2_relation', 'nominee2_percent'],
        'society' => ['account_code', 'amount'],
        'investments' => ['type', 'name', 'institution', 'instrument_no', 'principal', 'invested_on', 'matures_on', 'expected_rate_percent', 'funded_from'],
    ];

    /** Rows formatted as text in the template (data area). */
    private const int TEXT_ROWS = 2000;

    public function binary(): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach (self::SHEETS as $key => $title) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);

            foreach (self::COLUMNS[$key] as $index => $column) {
                $sheet->getCell([$index + 1, 1])->setValue($column);
                $sheet->getCell([$index + 1, 2])->setValue($this->text('golive.columns.'.$column, 'bn').' / '.$this->text('golive.columns.'.$column, 'en'));
                $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
            }

            $last = $sheet->getHighestColumn();
            $sheet->getStyle('A1:'.$last.'2')->getFont()->setBold(true);
            $sheet->getStyle('A3:'.$last.(self::TEXT_ROWS + 2))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->freezePane('A3');
        }

        $instructions = $book->createSheet();
        $instructions->setTitle('Instructions');

        foreach ([...$this->lines('bn'), '', ...$this->lines('en')] as $row => $line) {
            $instructions->getCell([1, $row + 1])->setValue($line);
        }

        $book->setActiveSheetIndex(0);
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw new \RuntimeException('Could not open a memory stream for the template.');
        }

        (new Xlsx($book))->save($stream);
        rewind($stream);
        $binary = (string) stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $binary;
    }

    private function text(string $key, string $locale): string
    {
        $value = __($key, [], $locale);

        return is_string($value) ? $value : $key;
    }

    /**
     * @return list<string>
     */
    private function lines(string $locale): array
    {
        $lines = __('golive.instructions', [], $locale);

        return is_array($lines) ? array_values(array_map(fn (mixed $line): string => is_string($line) ? $line : '', $lines)) : [];
    }
}
