<?php

declare(strict_types=1);

namespace App\Domain\Exits\Data;

use App\Support\Money\Money;

/**
 * BR-22: settlement = savings + unused advance + unpaid dividend (+ fees returned on prepaid months
 * after the exit) − what the member still owes − exit fee. Share capital is not held per member.
 */
final readonly class ExitPreview
{
    public function __construct(
        public Money $savings,
        public Money $advance,
        public Money $dividends,
        public Money $releasedFees,
        public Money $receivables,
        public Money $exitFee,
    ) {}

    public function net(): Money
    {
        return $this->savings->plus($this->advance)->plus($this->dividends)->plus($this->releasedFees)
            ->minus($this->receivables)->minus($this->exitFee);
    }
}
