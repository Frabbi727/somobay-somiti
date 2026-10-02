<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Exceptions\InvalidMoneyAmount;
use App\Support\Money\Exceptions\MoneyOverflow;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use JsonSerializable;

/**
 * An immutable taka amount held as integer poisha (৳1 = 100 poisha). SOMITI_SPEC.md §6.1.
 *
 * Every money value in the application passes through this class; floats are never used.
 */
final readonly class Money implements JsonSerializable
{
    public const int POISHA_PER_TAKA = 100;

    private function __construct(public int $poisha) {}

    public static function ofPoisha(int $poisha): self
    {
        return new self($poisha);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse user input such as "1234.5", "1,234.50", "৳ ১,২৩৪.৫০" or "-20" into poisha.
     *
     * @throws InvalidMoneyAmount when the text is not a number with at most two decimals
     * @throws MoneyOverflow when the amount does not fit in 64-bit poisha
     */
    public static function ofTaka(string $amount): self
    {
        $normalized = str_replace(
            ['৳', ',', ' ', "\u{00A0}"],
            '',
            BanglaNumber::toAscii(trim($amount)),
        );

        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches) !== 1) {
            throw InvalidMoneyAmount::unparseable($amount);
        }

        $poisha = BigInteger::of($matches[2].str_pad($matches[3] ?? '', 2, '0'));

        if ($matches[1] === '-') {
            $poisha = $poisha->negated();
        }

        try {
            return new self($poisha->toInt());
        } catch (IntegerOverflowException) {
            throw MoneyOverflow::create();
        }
    }

    /**
     * Like ofTaka(), but returns null instead of throwing on invalid input.
     */
    public static function tryOfTaka(string $amount): ?self
    {
        try {
            return self::ofTaka($amount);
        } catch (InvalidMoneyAmount|MoneyOverflow) {
            return null;
        }
    }

    /**
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function plus(self $other): self
    {
        $a = $this->poisha;
        $b = $other->poisha;

        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            throw MoneyOverflow::create();
        }

        return new self($a + $b);
    }

    public function minus(self $other): self
    {
        return $this->plus($other->negated());
    }

    public function multipliedByInt(int $factor): self
    {
        try {
            return new self(BigInteger::of($this->poisha)->multipliedBy($factor)->toInt());
        } catch (IntegerOverflowException) {
            throw MoneyOverflow::create();
        }
    }

    /**
     * A percentage of this amount, rounded HALF_UP to the poisha (§6.2).
     */
    public function percentOfBps(Bps $bps): self
    {
        return new self(Rounding::halfUpBps($this->poisha, $bps->value));
    }

    /**
     * Split this amount by integer weights with the largest-remainder method (§6.3).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return array<TKey, self>
     */
    public function allocate(array $weights): array
    {
        return array_map(
            fn (int $poisha): self => new self($poisha),
            Allocator::largestRemainder($this->poisha, $weights),
        );
    }

    public function negated(): self
    {
        if ($this->poisha === PHP_INT_MIN) {
            throw MoneyOverflow::create();
        }

        return new self(-$this->poisha);
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    public function min(self $other): self
    {
        return $this->poisha <= $other->poisha ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->poisha >= $other->poisha ? $this : $other;
    }

    /**
     * @return -1|0|1
     */
    public function compare(self $other): int
    {
        return $this->poisha <=> $other->poisha;
    }

    public function equals(self $other): bool
    {
        return $this->poisha === $other->poisha;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->poisha > $other->poisha;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->poisha >= $other->poisha;
    }

    public function isLessThan(self $other): bool
    {
        return $this->poisha < $other->poisha;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->poisha <= $other->poisha;
    }

    public function isZero(): bool
    {
        return $this->poisha === 0;
    }

    public function isPositive(): bool
    {
        return $this->poisha > 0;
    }

    public function isNegative(): bool
    {
        return $this->poisha < 0;
    }

    /**
     * Plain decimal taka with two decimals and no grouping, e.g. "-1234.50". Used for form state.
     */
    public function toTakaString(): string
    {
        $text = (string) $this->poisha;
        $sign = str_starts_with($text, '-') ? '-' : '';
        $digits = str_pad(ltrim($text, '-'), 3, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    /**
     * Display form, e.g. "৳ ১২,৩৪৫.৫০" for bn or "৳ 12,345.50" for en. Never rounds.
     */
    public function format(string $locale = 'bn', bool $lakh = false): string
    {
        $text = $this->toTakaString();
        $sign = str_starts_with($text, '-') ? '-' : '';
        [$taka, $poisha] = explode('.', ltrim($text, '-'));

        $formatted = $sign.'৳ '.BanglaNumber::group($taka, $lakh).'.'.$poisha;

        return $locale === 'bn' ? BanglaNumber::digits($formatted) : $formatted;
    }

    public function jsonSerialize(): int
    {
        return $this->poisha;
    }
}
