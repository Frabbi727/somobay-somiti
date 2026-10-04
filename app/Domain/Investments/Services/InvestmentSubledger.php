<?php

declare(strict_types=1);

namespace App\Domain\Investments\Services;

use App\Domain\Accounting\Contracts\SubledgerSource;
use App\Domain\Investments\Enums\InvestmentType;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The investment register for one kind of investment; its 13xx account must agree with it.
 */
final readonly class InvestmentSubledger implements SubledgerSource
{
    public function __construct(private InvestmentType $type) {}

    public function accountCode(): string
    {
        return $this->type->accountCode();
    }

    public function label(): string
    {
        return __('investments.register').' · '.$this->type->getLabel();
    }

    public function balanceAsOf(CarbonImmutable $date): Money
    {
        return Money::ofPoisha((int) DB::table('investment_ledger_entries as l')
            ->join('investments as i', 'i.id', '=', 'l.investment_id')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('i.type', $this->type->value)
            ->where('e.entry_date', '<=', $date->toDateString())
            ->sum('l.delta_poisha'));
    }
}
