<?php

declare(strict_types=1);

namespace App\Domain\Investments\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Enums\InvestmentType;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class InvestmentData
{
    public function __construct(
        public InvestmentType $type,
        public string $institution,
        public Money $principal,
        public PaymentMethod $fundedFrom,
        public CarbonImmutable $investedOn,
        public string $idempotencyKey,
        public ?string $instrumentNo = null,
        public ?CarbonImmutable $maturesOn = null,
        public ?Bps $expectedRate = null,
        public ?string $notes = null,
        public ?string $attachmentPath = null,
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

        $type = $data['type'] ?? null;
        $fundedFrom = $data['funded_from'] ?? null;
        $principal = $data['principal'] ?? null;
        $rate = $text('expected_rate');

        return new self(
            type: $type instanceof InvestmentType ? $type : InvestmentType::from((string) $type),
            institution: $text('institution') ?? '',
            principal: $principal instanceof Money ? $principal : Money::ofTaka((string) ($principal ?? '0')),
            fundedFrom: $fundedFrom instanceof PaymentMethod ? $fundedFrom : PaymentMethod::from((string) $fundedFrom),
            investedOn: CarbonImmutable::parse($text('invested_on') ?? 'today'),
            idempotencyKey: $text('idempotency_key') ?? (string) Str::uuid(),
            instrumentNo: $text('instrument_no'),
            maturesOn: $text('matures_on') === null ? null : CarbonImmutable::parse((string) $text('matures_on')),
            expectedRate: $rate === null ? null : Bps::ofPercent($rate),
            notes: $text('notes'),
            attachmentPath: $text('attachment_path'),
            resolutionId: is_numeric($data['resolution_id'] ?? null) ? (int) $data['resolution_id'] : null,
        );
    }
}
