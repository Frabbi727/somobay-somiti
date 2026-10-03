<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class TrialBalanceReport
{
    /**
     * @param  list<TrialBalanceRow>  $rows
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public array $rows,
    ) {}

    /**
     * Accounts that still carry a balance; accounts whose postings net to zero are left out.
     *
     * @return list<TrialBalanceRow>
     */
    public function balanceRows(): array
    {
        return array_values(array_filter($this->rows, fn (TrialBalanceRow $row): bool => ! $row->net()->isZero()));
    }

    public function totalDebit(): Money
    {
        return Money::sum(array_map(fn (TrialBalanceRow $row): Money => $row->debitBalance(), $this->rows));
    }

    public function totalCredit(): Money
    {
        return Money::sum(array_map(fn (TrialBalanceRow $row): Money => $row->creditBalance(), $this->rows));
    }

    public function difference(): Money
    {
        return $this->totalDebit()->minus($this->totalCredit());
    }

    public function isBalanced(): bool
    {
        return $this->difference()->isZero();
    }
}
