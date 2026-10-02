<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Enums\VoucherType;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class JournalDraftData
{
    /**
     * @param  list<array{account_id: int, member_id: int|null, debit_poisha: int, credit_poisha: int, memo: string|null}>  $lines
     */
    public function __construct(
        public VoucherType $type,
        public CarbonImmutable $entryDate,
        public string $narration,
        public array $lines,
    ) {}

    /**
     * Build from Filament form state, where debit/credit have already been parsed to Money.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $type = $data['voucher_type'] ?? null;
        $lines = [];

        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (! is_array($line) || blank($line['account_id'] ?? null)) {
                continue;
            }

            $member = $line['member_id'] ?? null;
            $memo = $line['memo'] ?? null;

            $lines[] = [
                'account_id' => (int) $line['account_id'],
                'member_id' => filled($member) ? (int) $member : null,
                'debit_poisha' => self::poisha($line['debit'] ?? null),
                'credit_poisha' => self::poisha($line['credit'] ?? null),
                'memo' => is_string($memo) && trim($memo) !== '' ? trim($memo) : null,
            ];
        }

        $date = $data['entry_date'] ?? null;

        return new self(
            type: $type instanceof VoucherType ? $type : VoucherType::from((string) $type),
            entryDate: $date instanceof CarbonImmutable ? $date : CarbonImmutable::parse((string) $date),
            narration: trim((string) ($data['narration'] ?? '')),
            lines: $lines,
        );
    }

    /**
     * @return list<JournalLineData>
     */
    public function journalLines(): array
    {
        return array_map(fn (array $line): JournalLineData => new JournalLineData(
            accountId: $line['account_id'],
            debit: Money::ofPoisha($line['debit_poisha']),
            credit: Money::ofPoisha($line['credit_poisha']),
            memberId: $line['member_id'],
            memo: $line['memo'],
        ), $this->lines);
    }

    private static function poisha(mixed $value): int
    {
        return match (true) {
            $value instanceof Money => $value->poisha,
            is_string($value) && trim($value) !== '' => Money::ofTaka($value)->poisha,
            default => 0,
        };
    }
}
