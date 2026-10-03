<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Contracts;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A sub-ledger that a control account must agree with, e.g. the member advance ledger
 * for 2111 (Phase 5). Register implementations with the "somiti.subledgers" container tag.
 */
interface SubledgerSource
{
    public function accountCode(): string;

    /**
     * A short, translated name for reports.
     */
    public function label(): string;

    /**
     * The sub-ledger total as of the date, debit-positive like the general ledger.
     */
    public function balanceAsOf(CarbonImmutable $date): Money;
}
