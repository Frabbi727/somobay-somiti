<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Statements;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Reads a bank or wallet statement exported as CSV (comma, semicolon or tab separated; UTF-8 or
 * UTF-16). Rows without an amount (opening balance, totals) are skipped; any other unreadable
 * row stops the import with its line number, so nothing is imported half-way.
 */
final class StatementCsvParser
{
    /**
     * Tried in this order; the first format that reads every date in the file wins
     * (day-first before month-first, as Bangladeshi banks write dates).
     *
     * @var list<string>
     */
    private const array DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'd-M-y', 'd/m/y', 'Y/m/d', 'M d, Y', 'm/d/Y'];

    /**
     * @return array{headers: list<string>, rows: array<int, array<string, string>>} rows keyed by file line number
     */
    public function read(string $contents): array
    {
        $contents = $this->toUtf8($contents);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if (count($lines) < 2) {
            throw DomainRuleViolation::because('statements.errors.empty');
        }

        $delimiter = $this->delimiter($lines[0]);
        $headers = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($lines[0], $delimiter, '"', ''));
        $rows = [];

        foreach (array_slice($lines, 1) as $index => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $row = [];

            foreach ($headers as $position => $header) {
                $row[$header] = trim((string) ($cells[$position] ?? ''));
            }

            $rows[$index + 2] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return non-empty-list<ParsedLine>
     */
    public function parse(string $contents, ?ColumnMap $map = null): array
    {
        ['headers' => $headers, 'rows' => $rows] = $this->read($contents);
        $map ??= ColumnMap::detect($headers);

        if (! $map->isUsable()) {
            throw DomainRuleViolation::because('statements.errors.columns', ['headers' => implode(', ', $headers)]);
        }

        $withAmounts = [];

        foreach ($rows as $lineNo => $row) {
            $amount = $this->amount($row, $map, $lineNo);

            if ($amount !== null && ! $amount->isZero()) {
                $withAmounts[$lineNo] = [$row, $amount];
            }
        }

        $format = $this->dateFormat(array_map(fn (array $pair): string => $pair[0][(string) $map->date] ?? '', $withAmounts));
        $parsed = [];

        foreach ($withAmounts as $lineNo => [$row, $amount]) {
            $balance = $map->balance === null ? null : $this->money($row[$map->balance] ?? '', $lineNo);
            $text = fn (?string $column): ?string => $column === null || ($row[$column] ?? '') === '' ? null : $row[$column];

            $parsed[] = new ParsedLine(
                lineNo: $lineNo,
                date: $this->date($row[(string) $map->date] ?? '', $format),
                amount: $amount,
                description: $text($map->description),
                reference: $text($map->reference),
                balance: $balance,
            );
        }

        if ($parsed === []) {
            throw DomainRuleViolation::because('statements.errors.empty');
        }

        return $parsed;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function amount(array $row, ColumnMap $map, int $lineNo): ?Money
    {
        if ($map->amount !== null) {
            return $this->money($row[$map->amount] ?? '', $lineNo);
        }

        $in = $map->credit === null ? null : $this->money($row[$map->credit] ?? '', $lineNo);
        $out = $map->debit === null ? null : $this->money($row[$map->debit] ?? '', $lineNo);

        if ($in === null && $out === null) {
            return null;
        }

        return ($in ?? Money::zero())->absolute()->minus(($out ?? Money::zero())->absolute());
    }

    /**
     * "1,234.50", "(1,234.50)", "BDT 500", "৳ ৫০০", "500.00 Dr" …; empty → null.
     */
    private function money(string $cell, int $lineNo): ?Money
    {
        $value = trim(BanglaNumber::toAscii($cell));

        if ($value === '' || $value === '-') {
            return null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $matches) === 1) {
            $value = $matches[1];
            $negative = true;
        }

        if (preg_match('/^(.*?)\s*(dr|cr)\.?$/i', $value, $matches) === 1) {
            $value = $matches[1];
            $negative = $negative || strtolower($matches[2]) === 'dr';
        }

        $value = trim((string) preg_replace('/^(bdt|tk\.?|৳)\s*/i', '', $value));
        $money = Money::tryOfTaka($value);

        if ($money === null) {
            throw DomainRuleViolation::because('statements.errors.amount', ['line' => $lineNo, 'value' => $cell]);
        }

        return $negative ? $money->absolute()->negated() : $money;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function dateFormat(array $values): string
    {
        foreach (self::DATE_FORMATS as $format) {
            $all = true;

            foreach ($values as $value) {
                if ($this->tryDate($value, $format) === null) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return $format;
            }
        }

        $first = array_key_first($values);

        throw DomainRuleViolation::because('statements.errors.date', ['line' => $first ?? 0, 'value' => $first === null ? '' : $values[$first]]);
    }

    private function date(string $value, string $format): CarbonImmutable
    {
        return $this->tryDate($value, $format) ?? throw DomainRuleViolation::because('statements.errors.date', ['line' => 0, 'value' => $value]);
    }

    private function tryDate(string $value, string $format): ?CarbonImmutable
    {
        // Drop a trailing time ("05/08/2026 14:31", "2026-08-05T09:00:00").
        $value = trim((string) preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp][Mm])?$/', '', BanglaNumber::toAscii(trim($value))));
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return CarbonImmutable::instance($parsed)->startOfDay();
    }

    private function delimiter(string $headerLine): string
    {
        $counts = [',' => substr_count($headerLine, ','), ';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            $contents = (string) mb_convert_encoding($contents, 'UTF-8', 'UTF-16');
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw DomainRuleViolation::because('statements.errors.encoding');
        }

        return $contents;
    }
}
