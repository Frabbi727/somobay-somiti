<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

/**
 * The workbook's rows as text, exactly as typed; '_row' is the Excel row number for error messages.
 */
final readonly class OpeningWorkbook
{
    /**
     * @param  list<array<string, string>>  $members
     * @param  list<array<string, string>>  $society
     * @param  list<array<string, string>>  $investments
     */
    public function __construct(
        public array $members,
        public array $society,
        public array $investments,
    ) {}

    /**
     * @return array{members: list<array<string, string>>, society: list<array<string, string>>, investments: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return ['members' => $this->members, 'society' => $this->society, 'investments' => $this->investments];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = fn (string $key): array => array_values(array_map(
            fn (mixed $row): array => array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', (array) $row),
            (array) ($data[$key] ?? []),
        ));

        return new self($rows('members'), $rows('society'), $rows('investments'));
    }
}
