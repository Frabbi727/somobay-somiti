<?php

declare(strict_types=1);

namespace App\Domain\Geo\Data;

/**
 * Where someone lives: the upazila, the union (when the upazila has any) and the street line.
 * Division and district follow from the upazila.
 */
final readonly class PostalAddress
{
    public function __construct(
        public int $upazilaId,
        public ?int $unionId,
        public string $line,
    ) {}

    /**
     * Reads `{prefix}_upazila_id`, `{prefix}_union_id` and `{prefix}_address_line`; null when nothing was entered.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data, string $prefix): ?self
    {
        $upazila = $data[$prefix.'_upazila_id'] ?? null;
        $union = $data[$prefix.'_union_id'] ?? null;
        $line = $data[$prefix.'_address_line'] ?? null;

        $upazilaId = is_numeric($upazila) ? (int) $upazila : 0;
        $text = is_scalar($line) ? trim((string) $line) : '';

        if ($upazilaId === 0 && $text === '') {
            return null;
        }

        return new self($upazilaId, is_numeric($union) && (int) $union > 0 ? (int) $union : null, $text);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toAttributes(string $prefix): array
    {
        return [
            $prefix.'_upazila_id' => $this->upazilaId > 0 ? $this->upazilaId : null,
            $prefix.'_union_id' => $this->unionId,
            $prefix.'_address_line' => $this->line === '' ? null : $this->line,
        ];
    }

    /**
     * The same address as stored on a model row.
     */
    public static function fromAttributes(object $row, string $prefix): ?self
    {
        return self::fromForm([
            $prefix.'_upazila_id' => $row->{$prefix.'_upazila_id'} ?? null,
            $prefix.'_union_id' => $row->{$prefix.'_union_id'} ?? null,
            $prefix.'_address_line' => $row->{$prefix.'_address_line'} ?? null,
        ], $prefix);
    }
}
