<?php

declare(strict_types=1);

namespace App\Domain\Members\Data;

final readonly class NomineeRelationData
{
    public function __construct(
        public string $key,
        public string $labelBn,
        public string $labelEn,
        public int $sort = 0,
        public bool $active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data  key, label_bn, label_en, sort, active
     */
    public static function fromForm(array $data): self
    {
        return new self(
            key: trim((string) ($data['key'] ?? '')),
            labelBn: trim((string) ($data['label_bn'] ?? '')),
            labelEn: trim((string) ($data['label_en'] ?? '')),
            sort: (int) ($data['sort'] ?? 0),
            active: (bool) ($data['active'] ?? true),
        );
    }
}
