<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * §6.4 / BR-13: settles open dues oldest month first; inside a month by the allocation order in
 * each due's rate snapshot (default late fee → service → registration → deposit).
 */
final class AllocationEngine
{
    /**
     * @param  Collection<int, Due>  $dues
     * @return Collection<int, Due>
     */
    public function order(Collection $dues): Collection
    {
        return $dues
            ->filter(fn (Due $due): bool => $due->outstanding_poisha->isPositive())
            ->sort(function (Due $a, Due $b): int {
                return $a->month->compare($b->month)
                    ?: $this->priority($a) <=> $this->priority($b)
                    ?: $a->id <=> $b->id;
            })
            ->values();
    }

    /**
     * @param  Collection<int, Due>  $dues
     * @return array{allocations: list<array{due: Due, amount: Money}>, remainder: Money}
     */
    public function allocate(Money $amount, Collection $dues): array
    {
        $remaining = $amount;
        $allocations = [];

        foreach ($this->order($dues) as $due) {
            if (! $remaining->isPositive()) {
                break;
            }

            $applied = $remaining->min($due->outstanding_poisha);
            $allocations[] = ['due' => $due, 'amount' => $applied];
            $remaining = $remaining->minus($applied);
        }

        return ['allocations' => $allocations, 'remainder' => $remaining];
    }

    private function priority(Due $due): int
    {
        $order = $due->snapshot['allocation_order'] ?? DueType::defaultAllocationOrder();
        $position = array_search($due->type->value, is_array($order) ? $order : [], true);

        return $position === false ? 99 : (int) $position;
    }
}
