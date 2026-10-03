<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class PaymentData
{
    public function __construct(
        public int $memberId,
        public PaymentMethod $method,
        public Money $amount,
        public CarbonImmutable $receivedOn,
        public string $idempotencyKey,
        public ?string $trxId = null,
        public ?string $proofPath = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $method = $data['method'] ?? null;
        $amount = $data['amount'] ?? null;
        $text = function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        return new self(
            memberId: (int) ($data['member_id'] ?? 0),
            method: $method instanceof PaymentMethod ? $method : PaymentMethod::from((string) $method),
            amount: $amount instanceof Money ? $amount : Money::ofTaka((string) $amount),
            receivedOn: CarbonImmutable::parse((string) ($data['received_on'] ?? 'today')),
            idempotencyKey: $text('idempotency_key') ?? (string) Str::uuid(),
            trxId: ($trx = $text('trx_id')) === null ? null : strtoupper($trx),
            proofPath: $text('proof_path'),
            notes: $text('notes'),
        );
    }
}
