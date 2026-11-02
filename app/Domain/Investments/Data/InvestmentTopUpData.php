<?php

declare(strict_types=1);

namespace App\Domain\Investments\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * More money put into an active investment.
 */
final readonly class InvestmentTopUpData
{
    public function __construct(
        public Money $amount,
        public PaymentMethod $fundedFrom,
        public CarbonImmutable $investedOn,
        public string $idempotencyKey,
        public ?string $notes = null,
        public ?int $resolutionId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $text = function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $amount = $data['amount'] ?? null;
        $fundedFrom = $data['funded_from'] ?? null;

        return new self(
            amount: $amount instanceof Money ? $amount : Money::ofTaka((string) ($amount ?? '0')),
            fundedFrom: $fundedFrom instanceof PaymentMethod ? $fundedFrom : PaymentMethod::from((string) $fundedFrom),
            investedOn: CarbonImmutable::parse($text('invested_on') ?? 'today'),
            idempotencyKey: $text('idempotency_key') ?? (string) Str::uuid(),
            notes: $text('notes'),
            resolutionId: is_numeric($data['resolution_id'] ?? null) ? (int) $data['resolution_id'] : null,
        );
    }
}
