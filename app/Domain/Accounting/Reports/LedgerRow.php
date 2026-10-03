<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class LedgerRow
{
    /**
     * @param  Money  $balance  running balance after this line, debit-positive
     */
    public function __construct(
        public CarbonImmutable $date,
        public int $entryId,
        public string $voucherNo,
        public string $narration,
        public ?int $memberId,
        public ?string $memo,
        public Money $debit,
        public Money $credit,
        public Money $balance,
    ) {}
}
