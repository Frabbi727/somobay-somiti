<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Models\Account;
use App\Support\Money\Money;

final readonly class JournalLineData
{
    public function __construct(
        public int $accountId,
        public Money $debit,
        public Money $credit,
        public ?int $memberId = null,
        public ?string $memo = null,
    ) {}

    public static function debit(Account|int $account, Money $amount, ?int $memberId = null, ?string $memo = null): self
    {
        return new self(self::id($account), $amount, Money::zero(), $memberId, $memo);
    }

    public static function credit(Account|int $account, Money $amount, ?int $memberId = null, ?string $memo = null): self
    {
        return new self(self::id($account), Money::zero(), $amount, $memberId, $memo);
    }

    /**
     * The same line on the opposite side, used for reversals.
     */
    public function mirrored(): self
    {
        return new self($this->accountId, $this->credit, $this->debit, $this->memberId, $this->memo);
    }

    private static function id(Account|int $account): int
    {
        return $account instanceof Account ? $account->id : $account;
    }
}
