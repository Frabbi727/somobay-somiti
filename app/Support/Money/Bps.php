<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Exceptions\InvalidMoneyAmount;
use JsonSerializable;

/**
 * A non-negative percentage stored as integer basis points (1% = 100 bps).
 */
final readonly class Bps implements JsonSerializable
{
    private function __construct(public int $value) {}

    public static function of(int $bps): self
    {
        if ($bps < 0) {
            throw InvalidMoneyAmount::negativeBps($bps);
        }

        return new self($bps);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a percentage such as "2", "2.5", "2.00%" or "২.৫০" into basis points.
     */
    public static function ofPercent(string $percent): self
    {
        $normalized = str_replace(['%', ' '], '', BanglaNumber::toAscii(trim($percent)));

        if (preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/', $normalized, $matches) !== 1) {
            throw InvalidMoneyAmount::unparseablePercent($percent);
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return new self((int) ($matches[1].$fraction));
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The percentage with exactly two decimals, e.g. 250 bps → "2.50".
     */
    public function toPercentString(): string
    {
        $padded = str_pad((string) $this->value, 3, '0', STR_PAD_LEFT);

        return substr($padded, 0, -2).'.'.substr($padded, -2);
    }

    /**
     * Display form, e.g. "২.৫০%" for bn or "2.50%" for en.
     */
    public function format(string $locale = 'bn'): string
    {
        $text = $this->toPercentString().'%';

        return $locale === 'bn' ? BanglaNumber::digits($text) : $text;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}
