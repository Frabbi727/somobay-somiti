<?php

declare(strict_types=1);

namespace App\Domain\Investments\Data;

use App\Domain\Investments\Enums\ReturnFrequency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class ExpectedReturnData
{
    public function __construct(
        public Money $amount,
        public ReturnFrequency $frequency,
        public CarbonImmutable $startsOn,
        public string $idempotencyKey,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $frequency = $data['frequency'] ?? null;
        $frequency = $frequency instanceof ReturnFrequency ? $frequency : ReturnFrequency::from((string) $frequency);
        $amount = $data['amount'] ?? null;
        $notes = is_string($data['notes'] ?? null) && trim($data['notes']) !== '' ? trim($data['notes']) : null;
        $key = is_string($data['idempotency_key'] ?? null) && $data['idempotency_key'] !== '' ? $data['idempotency_key'] : (string) Str::uuid();

        return new self(
            amount: $frequency === ReturnFrequency::None ? Money::zero() : ($amount instanceof Money ? $amount : Money::ofTaka((string) ($amount ?? '0'))),
            frequency: $frequency,
            startsOn: CarbonImmutable::parse((string) ($data['starts_on'] ?? 'today')),
            idempotencyKey: $key,
            notes: $notes,
        );
    }
}
