<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Contracts;

use App\Support\Money\Money;

/**
 * Money members have paid ahead (liability 2111), per member.
 */
interface AdvanceBalances
{
    /**
     * @return array<int, Money> member id => positive advance balance; members without advance are omitted
     */
    public function all(): array;
}
