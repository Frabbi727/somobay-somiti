<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\InvalidMoneyAmount;
use Brick\Math\BigInteger;
use LogicException;

/**
 * Largest-remainder allocation (SOMITI_SPEC.md §6.3, Fowler's "Foemmel's conundrum").
 *
 * Splits a total in proportion to integer weights so that the parts always sum exactly
 * to the total and each part is within one poisha of its exact share.
 */
final class Allocator
{
    /**
     * Ties between equal remainders go to the key that appears first in $weights.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return array<TKey, int>
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        if ($weights === []) {
            throw InvalidMoneyAmount::invalidWeights('at least one weight is required');
        }

        $weightSum = BigInteger::zero();

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw InvalidMoneyAmount::invalidWeights('weights must not be negative');
            }

            $weightSum = $weightSum->plus($weight);
        }

        if ($weightSum->isZero()) {
            throw InvalidMoneyAmount::invalidWeights('weights must not all be zero');
        }

        $isNegative = $total < 0;
        $magnitude = BigInteger::of($total)->abs();

        $quotients = [];
        $remainders = [];
        $allocated = BigInteger::zero();

        foreach ($weights as $key => $weight) {
            $product = $magnitude->multipliedBy($weight);
            $quotients[$key] = $product->quotient($weightSum);
            $remainders[$key] = $product->remainder($weightSum);
            $allocated = $allocated->plus($quotients[$key]);
        }

        $leftover = $magnitude->minus($allocated)->toInt();

        $order = array_keys($weights);
        $position = array_flip($order);

        usort($order, function (int|string $a, int|string $b) use ($remainders, $position): int {
            return $remainders[$b]->compareTo($remainders[$a]) ?: $position[$a] <=> $position[$b];
        });

        foreach (array_slice($order, 0, $leftover) as $key) {
            $quotients[$key] = $quotients[$key]->plus(1);
        }

        $parts = [];
        $check = BigInteger::zero();

        foreach ($quotients as $key => $quotient) {
            $part = $isNegative ? $quotient->negated() : $quotient;
            $parts[$key] = $part->toInt();
            $check = $check->plus($part);
        }

        if (! $check->isEqualTo($total)) {
            throw new LogicException('Allocation does not sum to the total.');
        }

        return $parts;
    }
}
