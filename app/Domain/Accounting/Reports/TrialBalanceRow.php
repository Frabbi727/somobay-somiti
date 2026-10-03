<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Domain\Accounting\Models\Account;
use App\Support\Money\Money;

final readonly class TrialBalanceRow
{
    public function __construct(
        public Account $account,
        public Money $totalDebit,
        public Money $totalCredit,
    ) {}

    /**
     * Debit minus credit; positive means a debit balance.
     */
    public function net(): Money
    {
        return $this->totalDebit->minus($this->totalCredit);
    }

    public function debitBalance(): Money
    {
        return $this->net()->max(Money::zero());
    }

    public function creditBalance(): Money
    {
        return $this->net()->negated()->max(Money::zero());
    }
}
