<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class ExpenseData
{
    public function __construct(
        public int $accountId,
        public PaymentMethod $paidFrom,
        public Money $amount,
        public CarbonImmutable $spentOn,
        public string $description,
        public string $idempotencyKey,
        public ?string $payee = null,
        public ?string $reference = null,
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

        $paidFrom = $data['paid_from'] ?? null;
        $amount = $data['amount'] ?? null;

        return new self(
            accountId: (int) ($data['account_id'] ?? 0),
            paidFrom: $paidFrom instanceof PaymentMethod ? $paidFrom : PaymentMethod::from((string) $paidFrom),
            amount: $amount instanceof Money ? $amount : Money::ofTaka((string) ($amount ?? '0')),
            spentOn: CarbonImmutable::parse($text('spent_on') ?? 'today'),
            description: $text('description') ?? '',
            idempotencyKey: $text('idempotency_key') ?? (string) Str::uuid(),
            payee: $text('payee'),
            reference: $text('reference'),
            attachmentPath: $text('attachment_path'),
        );
    }
}
