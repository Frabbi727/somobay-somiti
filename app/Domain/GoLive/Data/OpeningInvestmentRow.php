<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Enums\InvestmentType;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A checked open investment, carried at its principal on the go-live date.
 */
final readonly class OpeningInvestmentRow
{
    public function __construct(
        public int $row,
        public InvestmentType $type,
        public string $name,
        public string $institution,
        public ?string $instrumentNo,
        public Money $principal,
        public CarbonImmutable $investedOn,
        public ?CarbonImmutable $maturesOn,
        public ?Bps $expectedRate,
        public PaymentMethod $fundedFrom,
    ) {}
}
