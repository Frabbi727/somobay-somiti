<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Domain\Accounting\Models\Account;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class LedgerReport
{
    /**
     * @param  list<LedgerRow>  $rows
     */
    public function __construct(
        public Account $account,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?int $memberId,
        public Money $opening,
        public array $rows,
    ) {}

    public function totalDebit(): Money
    {
        return Money::sum(array_map(fn (LedgerRow $row): Money => $row->debit, $this->rows));
    }

    public function totalCredit(): Money
    {
        return Money::sum(array_map(fn (LedgerRow $row): Money => $row->credit, $this->rows));
    }

    public function closing(): Money
    {
        return $this->opening->plus($this->totalDebit())->minus($this->totalCredit());
    }
}
