<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Statements;

/**
 * Which CSV column holds which field. Either an `amount` column (signed, + = money in) or
 * `credit` (money in) and/or `debit` (money out) columns are required.
 */
final readonly class ColumnMap
{
    /**
     * Header names recognised automatically (compared lower-case, punctuation removed).
     *
     * @var array<string, list<string>>
     */
    private const array SYNONYMS = [
        'date' => ['date', 'txn date', 'trans date', 'transaction date', 'posting date', 'value date', 'তারিখ', 'লেনদেনের তারিখ'],
        'description' => ['description', 'details', 'particulars', 'narration', 'remarks', 'transaction details', 'বিবরণ'],
        'reference' => ['reference', 'ref', 'ref no', 'reference no', 'trxid', 'trx id', 'transaction id', 'txn id', 'cheque', 'cheque no', 'chq no', 'instrument no', 'রেফারেন্স'],
        'credit' => ['credit', 'deposit', 'deposits', 'cr', 'in', 'money in', 'received', 'cash in', 'জমা'],
        'debit' => ['debit', 'withdrawal', 'withdrawals', 'dr', 'out', 'money out', 'paid out', 'cash out', 'উত্তোলন'],
        'amount' => ['amount', 'transaction amount', 'পরিমাণ'],
        'balance' => ['balance', 'closing balance', 'running balance', 'available balance', 'ব্যালেন্স', 'স্থিতি'],
    ];

    public function __construct(
        public ?string $date,
        public ?string $description = null,
        public ?string $reference = null,
        public ?string $amount = null,
        public ?string $credit = null,
        public ?string $debit = null,
        public ?string $balance = null,
    ) {}

    /**
     * @param  list<string>  $headers
     */
    public static function detect(array $headers): self
    {
        $found = [];

        foreach (self::SYNONYMS as $field => $names) {
            foreach ($headers as $header) {
                if (in_array(self::normalize($header), $names, true) && ! in_array($header, $found, true)) {
                    $found[$field] = $header;
                    break;
                }
            }
        }

        return new self(
            date: $found['date'] ?? null,
            description: $found['description'] ?? null,
            reference: $found['reference'] ?? null,
            amount: $found['amount'] ?? null,
            credit: $found['credit'] ?? null,
            debit: $found['debit'] ?? null,
            balance: $found['balance'] ?? null,
        );
    }

    /**
     * Fields chosen by the user win over the detected ones.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        $pick = fn (string $key, ?string $current): ?string => is_string($overrides[$key] ?? null) && $overrides[$key] !== '' ? $overrides[$key] : $current;

        return new self(
            date: $pick('date', $this->date),
            description: $pick('description', $this->description),
            reference: $pick('reference', $this->reference),
            amount: $pick('amount', $this->amount),
            credit: $pick('credit', $this->credit),
            debit: $pick('debit', $this->debit),
            balance: $pick('balance', $this->balance),
        );
    }

    public function isUsable(): bool
    {
        return $this->date !== null && ($this->amount !== null || $this->credit !== null || $this->debit !== null);
    }

    public static function normalize(string $header): string
    {
        $lower = mb_strtolower(trim($header));

        return trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $lower));
    }
}
