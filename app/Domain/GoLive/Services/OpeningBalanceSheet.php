<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\GoLive\Data\OpeningInvestmentRow;
use App\Domain\GoLive\Data\ValidatedOpening;
use App\Support\Money\Money;

/**
 * The opening journal and its summary from validated rows. Used by both the preview and the
 * post, so the posted voucher is exactly what was previewed. The difference between what the
 * society holds and what it owes goes to Accumulated Surplus (3901); each investment comes in on
 * its own voucher against 3901. Arrears are not journaled:
 * like every due, they reach the books only when paid.
 */
final class OpeningBalanceSheet
{
    public function __construct(private readonly Accounts $accounts) {}

    /**
     * @return array{members: int, shares: int, savings_poisha: int, advance_poisha: int, arrears_poisha: int, investments: int, surplus_poisha: int, lines: list<array{code: string, debit_poisha: int, credit_poisha: int}>}
     */
    public function summary(ValidatedOpening $opening): array
    {
        $byCode = [];

        foreach ($this->entries($opening, []) as [$code, $debit, $credit]) {
            $byCode[$code] ??= ['code' => $code, 'debit_poisha' => 0, 'credit_poisha' => 0];
            $byCode[$code]['debit_poisha'] += $debit->poisha;
            $byCode[$code]['credit_poisha'] += $credit->poisha;
        }

        ksort($byCode);

        $shares = 0;
        $savings = Money::zero();
        $advance = Money::zero();
        $arrears = Money::zero();

        foreach ($opening->members as $member) {
            $shares += $member->shares;
            $savings = $savings->plus($member->savings);
            $advance = $advance->plus($member->advance);
            $arrears = $arrears->plus($member->arrearsDeposit)->plus($member->arrearsFees);
        }

        return [
            'members' => count($opening->members),
            'shares' => $shares,
            'savings_poisha' => $savings->poisha,
            'advance_poisha' => $advance->poisha,
            'arrears_poisha' => $arrears->poisha,
            'investments' => count($opening->investments),
            'surplus_poisha' => $this->surplus($opening)->poisha,
            'lines' => array_values($byCode),
        ];
    }

    /**
     * The main opening voucher: cash, bank, wallets, society funds and member balances, with 3901
     * balancing it. Investments are left out: each gets its own voucher (investmentLines), because
     * an investment is reversed or closed through its own voucher.
     *
     * @param  array<string, int>  $memberIdsByNo
     * @return list<JournalLineData>
     */
    public function journalLines(ValidatedOpening $opening, array $memberIdsByNo): array
    {
        $lines = [];

        foreach ($this->entries($opening, $memberIdsByNo, withInvestments: false) as [$code, $debit, $credit, $memberId, $memo]) {
            $account = $this->accounts->byCode($code);
            $lines[] = $debit->isPositive()
                ? JournalLineData::debit($account, $debit, $memberId, $memo)
                : JournalLineData::credit($account, $credit, $memberId, $memo);
        }

        return $lines;
    }

    /**
     * One investment's opening voucher: Dr its 13xx account, Cr accumulated surplus.
     *
     * @return list<JournalLineData>
     */
    public function investmentLines(OpeningInvestmentRow $row): array
    {
        $memo = self::investmentMemo($row);

        return [
            JournalLineData::debit($this->accounts->byCode($row->type->accountCode()), $row->principal, null, $memo),
            JournalLineData::credit($this->accounts->byCode(AccountCode::ACCUMULATED_SURPLUS), $row->principal, null, $memo),
        ];
    }

    /**
     * Positive = the society holds more than it owes (credit 3901).
     */
    public function surplus(ValidatedOpening $opening, bool $withInvestments = true): Money
    {
        $held = Money::zero();
        $owed = Money::zero();

        foreach ($opening->society as $row) {
            if (in_array($row->accountCode, OpeningImportValidator::DEBIT_CODES, true)) {
                $held = $held->plus($row->amount);
            } else {
                $owed = $owed->plus($row->amount);
            }
        }

        if ($withInvestments) {
            foreach ($opening->investments as $row) {
                $held = $held->plus($row->principal);
            }
        }

        foreach ($opening->members as $row) {
            $owed = $owed->plus($row->savings)->plus($row->advance);
        }

        return $held->minus($owed);
    }

    /**
     * The memo on an investment's opening line: its name, else institution and instrument.
     */
    public static function investmentMemo(OpeningInvestmentRow $row): string
    {
        return $row->name !== '' ? $row->name : trim($row->institution.' '.($row->instrumentNo ?? ''));
    }

    /**
     * Non-zero entries in posting order: society debits, investments, members, society credits, 3901.
     * Without investments, 3901 balances the remaining lines on their own.
     *
     * @param  array<string, int>  $memberIdsByNo
     * @return list<array{0: string, 1: Money, 2: Money, 3: int|null, 4: string|null}>
     */
    private function entries(ValidatedOpening $opening, array $memberIdsByNo, bool $withInvestments = true): array
    {
        $zero = Money::zero();
        $entries = [];

        foreach ($opening->society as $row) {
            if ($row->amount->isPositive() && in_array($row->accountCode, OpeningImportValidator::DEBIT_CODES, true)) {
                $entries[] = [$row->accountCode, $row->amount, $zero, null, null];
            }
        }

        if ($withInvestments) {
            foreach ($opening->investments as $row) {
                $entries[] = [$row->type->accountCode(), $row->principal, $zero, null, self::investmentMemo($row)];
            }
        }

        foreach ($opening->members as $row) {
            $memberId = $memberIdsByNo[$row->memberNo] ?? null;

            if ($row->savings->isPositive()) {
                $entries[] = [AccountCode::MEMBER_SAVINGS, $zero, $row->savings, $memberId, $row->memberNo];
            }

            if ($row->advance->isPositive()) {
                $entries[] = [AccountCode::MEMBER_ADVANCE, $zero, $row->advance, $memberId, $row->memberNo];
            }
        }

        foreach ($opening->society as $row) {
            if ($row->amount->isPositive() && in_array($row->accountCode, OpeningImportValidator::CREDIT_CODES, true)) {
                $entries[] = [$row->accountCode, $zero, $row->amount, null, null];
            }
        }

        $surplus = $this->surplus($opening, $withInvestments);

        if ($surplus->isPositive()) {
            $entries[] = [AccountCode::ACCUMULATED_SURPLUS, $zero, $surplus, null, null];
        } elseif ($surplus->isNegative()) {
            $entries[] = [AccountCode::ACCUMULATED_SURPLUS, $surplus->negated(), $zero, null, null];
        }

        return $entries;
    }
}
