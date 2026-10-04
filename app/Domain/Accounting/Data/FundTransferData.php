<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class FundTransferData
{
    public function __construct(
        public PaymentMethod $from,
        public PaymentMethod $to,
        public Money $amount,
        public Money $charge,
        public CarbonImmutable $transferredOn,
        public string $idempotencyKey,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?string $attachmentPath = null,
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

        $method = fn (string $key): PaymentMethod => ($data[$key] ?? null) instanceof PaymentMethod
            ? $data[$key]
            : PaymentMethod::from((string) ($data[$key] ?? ''));

        $money = fn (string $key): Money => ($data[$key] ?? null) instanceof Money
            ? $data[$key]
            : Money::ofTaka((string) ($text($key) ?? '0'));

        return new self(
            from: $method('from_method'),
            to: $method('to_method'),
            amount: $money('amount'),
            charge: $money('charge'),
            transferredOn: CarbonImmutable::parse($text('transferred_on') ?? 'today'),
            idempotencyKey: $text('idempotency_key') ?? (string) Str::uuid(),
            reference: $text('reference'),
            notes: $text('notes'),
            attachmentPath: $text('attachment_path'),
        );
    }
}
