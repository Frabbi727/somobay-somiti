<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\MoneyOverflow;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The only place where money is rounded (SOMITI_SPEC.md §6.2).
 *
 * All arithmetic is exact integer math; HALF_UP rounds ties away from zero.
 */
final class Rounding
{
    public const int BPS_DENOMINATOR = 10_000;

    /**
     * Apply a basis-point rate to a poisha amount, rounding HALF_UP to the poisha.
     *
     * Equivalent to intdiv(base × bps + 5000, 10000) for non-negative bases, without overflow.
     */
    public static function halfUpBps(int $basePoisha, int $bps): int
    {
        return self::divideHalfUp(
            BigInteger::of($basePoisha)->multipliedBy($bps),
            self::BPS_DENOMINATOR,
        );
    }

    /**
     * Compute numerator × multiplier ÷ divisor, rounding HALF_UP to the poisha.
     */
    public static function halfUpRatio(int $numerator, int $multiplier, int $divisor): int
    {
        return self::divideHalfUp(BigInteger::of($numerator)->multipliedBy($multiplier), $divisor);
    }

    private static function divideHalfUp(BigInteger $dividend, int $divisor): int
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('Rounding divisor must be positive.');
        }

        try {
            return $dividend->dividedBy($divisor, RoundingMode::HalfUp)->toInt();
        } catch (IntegerOverflowException) {
            throw MoneyOverflow::create();
        }
    }
}
