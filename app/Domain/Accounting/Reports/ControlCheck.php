<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Domain\Accounting\Models\Account;
use App\Support\Money\Money;

/**
 * One control account compared with its sub-ledger (§6.7 invariant 2). Balances are debit-positive.
 */
final readonly class ControlCheck
{
    public function __construct(
        public Account $account,
        public string $source,
        public Money $generalLedger,
        public Money $subledger,
    ) {}

    public function difference(): Money
    {
        return $this->generalLedger->minus($this->subledger);
    }

    public function isReconciled(): bool
    {
        return $this->difference()->isZero();
    }
}
