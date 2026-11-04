<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the go-live workbook into text rows. Cells are read as the text Excel shows (or the
 * number's full PHP string), never converted to numbers here, so nothing is rounded.
 */
final class OpeningWorkbookReader
{
    /**
     * Columns where a number can only be a date Excel stored as a serial (a pasted real date).
     */
    private const DATE_COLUMNS = ['joined_on', 'invested_on', 'matures_on'];

    public function read(string $absolutePath): OpeningWorkbook
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $book = $reader->load($absolutePath);
        } catch (Throwable) {
            throw DomainRuleViolation::because('golive.errors.unreadable');
        }

        $rows = [];

        foreach (OpeningTemplate::SHEETS as $key => $title) {
            $sheet = $book->getSheetByName($title) ?? throw DomainRuleViolation::because('golive.errors.missing_sheet', ['sheet' => $title]);
            $rows[$key] = $this->rows($sheet, OpeningTemplate::COLUMNS[$key], $title);
        }

        $book->disconnectWorksheets();

        return new OpeningWorkbook($rows['members'], $rows['society'], $rows['investments']);
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, string>>
     */
    private function rows(Worksheet $sheet, array $columns, string $title): array
    {
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $positions = [];

        for ($col = 1; $col <= $highestColumn; $col++) {
            $header = trim($this->cell($sheet, $col, 1));

            if ($header !== '') {
                $positions[$header] = $col;
            }
        }

        foreach ($columns as $column) {
            if (! isset($positions[$column])) {
                throw DomainRuleViolation::because('golive.errors.missing_column', ['sheet' => $title, 'column' => $column]);
            }
        }

        $rows = [];

        for ($row = 3, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $values = [];

            foreach ($columns as $column) {
                $values[$column] = trim($this->cell($sheet, $positions[$column], $row, in_array($column, self::DATE_COLUMNS, true)));
            }

            if (implode('', $values) !== '') {
                $rows[] = ['_row' => (string) $row, ...$values];
            }
        }

        return $rows;
    }

    private function cell(Worksheet $sheet, int $column, int $row, bool $isDate = false): string
    {
        $value = $sheet->getCell([$column, $row])->getValue();

        if ($isDate && (is_int($value) || is_float($value))) {
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
