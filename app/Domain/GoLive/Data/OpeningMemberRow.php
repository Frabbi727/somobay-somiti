<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

use App\Domain\Members\Data\MemberData;
use App\Support\Money\Money;

/**
 * A checked member row: who the member is and what they hold at go-live.
 */
final readonly class OpeningMemberRow
{
    public function __construct(
        public int $row,
        public string $memberNo,
        public MemberData $member,
        public int $shares,
        public Money $savings,
        public Money $advance,
        public Money $arrearsDeposit,
        public Money $arrearsFees,
    ) {}
}
