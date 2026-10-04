<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Data;

use App\Support\Money\Money;

/**
 * Everything a year-end posts, computed in one place so the preview and the posting agree.
 */
final readonly class YearEndFigures
{
    /**
     * @param  array<int, Money>  $accountBalances  income/expense account id => balance to close (credit-positive)
     * @param  array<string, Money>  $appropriation  reserve, development_fund, bad_debt_fund, other_funds
     * @param  array<int, int>  $shareMonths  member id => share-months in the year
     * @param  array<int, Money>  $dividends  member id => dividend
     */
    public function __construct(
        public AppropriationRates $rates,
        public Money $netProfit,
        public Money $priorLoss,
        public Money $lossOffset,
        public array $accountBalances,
        public array $appropriation,
        public Money $dividendPool,
        public array $shareMonths,
        public array $dividends,
    ) {}

    public function isProfit(): bool
    {
        return $this->netProfit->isPositive();
    }

    public function totalShareMonths(): int
    {
        return array_sum($this->shareMonths);
    }

    /**
     * Changes whenever anything that drives the year-end changes, so stale drafts are caught.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->netProfit->poisha,
            $this->priorLoss->poisha,
            array_map(fn ($rate): int => $rate->value, $this->rates->byKey()),
            array_map(fn (Money $balance): int => $balance->poisha, $this->accountBalances),
            $this->shareMonths,
        ]));
    }

    /**
     * @return array<string, int>
     */
    public function appropriationPoisha(): array
    {
        return array_map(fn (Money $amount): int => $amount->poisha, $this->appropriation);
    }
}
