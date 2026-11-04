<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

/**
 * One problem in the go-live workbook: where it is (sheet, Excel row, column) and what it is.
 */
final readonly class OpeningImportError
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        public string $sheet,
        public int $row,
        public ?string $column,
        public string $key,
        public array $replace = [],
    ) {}

    public function message(): string
    {
        $message = __($this->key, $this->replace);

        return is_string($message) ? $message : $this->key;
    }

    /**
     * @return array{sheet: string, row: int, column: string|null, key: string, replace: array<string, string>}
     */
    public function toArray(): array
    {
        return ['sheet' => $this->sheet, 'row' => $this->row, 'column' => $this->column, 'key' => $this->key, 'replace' => $this->replace];
    }
}
