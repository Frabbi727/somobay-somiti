<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use App\Support\Money\Money;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A small builder for report workbooks. Money cells are written as numeric taka with
 * two decimals (from Money's exact decimal text), so totals in Excel match the app.
 */
final class Workbook
{
    private readonly Spreadsheet $spreadsheet;

    private readonly Worksheet $sheet;

    private int $row = 1;

    public function __construct(string $title)
    {
        $this->spreadsheet = new Spreadsheet;
        $this->sheet = $this->spreadsheet->getActiveSheet();
        $this->sheet->setTitle(mb_substr($title, 0, 31));
    }

    /**
     * @param  list<string>  $cells
     */
    public function title(array $cells): self
    {
        $this->writeRow($cells, bold: true);

        return $this;
    }

    /**
     * @param  list<string|int|Money|null>  $cells
     */
    public function row(array $cells, bool $bold = false): self
    {
        $this->writeRow($cells, $bold);

        return $this;
    }

    public function blank(): self
    {
        $this->row++;

        return $this;
    }

    public function toBinary(): string
    {
        foreach (range(1, max(1, Coordinate::columnIndexFromString($this->sheet->getHighestColumn()))) as $column) {
            $this->sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            return '';
        }

        (new Xlsx($this->spreadsheet))->save($stream);
        rewind($stream);
        $binary = (string) stream_get_contents($stream);
        fclose($stream);
        $this->spreadsheet->disconnectWorksheets();

        return $binary;
    }

    /**
     * @param  list<string|int|Money|null>  $cells
     */
    private function writeRow(array $cells, bool $bold): void
    {
        foreach ($cells as $index => $value) {
            $coordinate = Coordinate::stringFromColumnIndex($index + 1).$this->row;
            $cell = $this->sheet->getCell($coordinate);

            match (true) {
                $value instanceof Money => $cell->setValueExplicit($value->toTakaString(), DataType::TYPE_NUMERIC),
                is_int($value) => $cell->setValueExplicit($value, DataType::TYPE_NUMERIC),
                $value === null => null,
                default => $cell->setValueExplicit($value, DataType::TYPE_STRING),
            };

            if ($value instanceof Money) {
                $this->sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('#,##0.00');
            }

            if ($bold) {
                $this->sheet->getStyle($coordinate)->getFont()->setBold(true);
            }
        }

        $this->row++;
    }
}
