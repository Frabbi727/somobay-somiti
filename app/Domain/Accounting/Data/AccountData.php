<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Enums\AccountType;

final readonly class AccountData
{
    public function __construct(
        public string $code,
        public string $nameEn,
        public string $nameBn,
        public AccountType $type,
        public bool $isControl = false,
        public bool $requiresMember = false,
        public ?string $description = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $description = $data['description'] ?? null;

        return new self(
            code: trim((string) ($data['code'] ?? '')),
            nameEn: trim((string) ($data['name_en'] ?? '')),
            nameBn: trim((string) ($data['name_bn'] ?? '')),
            type: $type instanceof AccountType ? $type : AccountType::from((string) $type),
            isControl: (bool) ($data['is_control'] ?? false),
            requiresMember: (bool) ($data['requires_member'] ?? false),
            description: is_string($description) && trim($description) !== '' ? trim($description) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'code' => $this->code,
            'name_en' => $this->nameEn,
            'name_bn' => $this->nameBn,
            'type' => $this->type,
            'normal_balance' => $this->type->normalBalance(),
            'is_control' => $this->isControl,
            'requires_member' => $this->requiresMember,
            'description' => $this->description,
        ];
    }
}
