<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Data\YearEndFigures;
use App\Domain\YearEnd\Models\YearEnd;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * W8 / BR-20 / BR-21: net profit for the year, the s.34(4) prior-loss offset, the statutory
 * appropriation (each line HALF_UP, the dividend pool absorbs the remainder so the lines sum to net
 * profit exactly) and the dividend by share-months (largest remainder, ties to the lower member id).
 */
final class YearEndCalculator
{
    public const string ACCUMULATED_SURPLUS = '3901';

    public function __construct(private readonly ShareMonths $shareMonths) {}

    public function calculate(FiscalYear $fiscalYear, AppropriationRates $rates): YearEndFigures
    {
        $rates->assertLawful();

        $balances = $this->incomeAndExpenseBalances($fiscalYear);
        $netProfit = Money::sum($balances);
        $priorLoss = $this->priorLoss($fiscalYear);

        $zero = array_map(fn (): Money => Money::zero(), AppropriationRates::ACCOUNTS);

        if (! $netProfit->isPositive()) {
            return new YearEndFigures($rates, $netProfit, $priorLoss, Money::zero(), $balances, $zero, Money::zero(), $this->shareMonths->forYear($fiscalYear->start_year), []);
        }

        // s.34(4): half the profit goes against earlier losses (never more than the loss).
        $half = $netProfit->percentOfBps(Bps::of((int) config('somiti.year_end.loss_offset_bps')));
        $offset = $half->isGreaterThan($priorLoss) ? $priorLoss : $half;
        $base = config('somiti.year_end.statutory_base') === 'after_loss_offset' ? $netProfit->minus($offset) : $netProfit;

        $appropriation = array_map(fn (Bps $rate): Money => $base->percentOfBps($rate), $rates->byKey());
        $pool = $netProfit->minus($offset)->minus(Money::sum($appropriation));

        if ($pool->isNegative()) {
            throw DomainRuleViolation::because('year_end.errors.over_appropriated');
        }

        $shareMonths = $this->shareMonths->forYear($fiscalYear->start_year);

        if ($pool->isPositive() && $shareMonths === []) {
            throw DomainRuleViolation::because('year_end.errors.no_shareholders');
        }

        return new YearEndFigures(
            rates: $rates,
            netProfit: $netProfit,
            priorLoss: $priorLoss,
            lossOffset: $offset,
            accountBalances: $balances,
            appropriation: $appropriation,
            dividendPool: $pool,
            shareMonths: $shareMonths,
            dividends: $pool->isPositive() ? $pool->allocate($shareMonths) : [],
        );
    }

    /**
     * Credit-positive balance of each income and expense account for the year (income positive,
     * expenses negative), leaving out the year-end's own closing entries.
     *
     * @return array<int, Money>
     */
    private function incomeAndExpenseBalances(FiscalYear $fiscalYear): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.type', [AccountType::Income->value, AccountType::Expense->value])
            ->where('e.fiscal_year_id', $fiscalYear->id)
            ->where(fn ($query) => $query->whereNull('e.source_type')->orWhere('e.source_type', '!=', (new YearEnd)->getMorphClass()))
            ->groupBy('l.account_id')
            ->orderBy('l.account_id')
            ->selectRaw('l.account_id, (SUM(l.credit_poisha) - SUM(l.debit_poisha))::bigint AS balance')
            ->pluck('balance', 'account_id');

        $balances = [];

        foreach ($rows as $accountId => $balance) {
            if ((int) $balance !== 0) {
                $balances[(int) $accountId] = Money::ofPoisha((int) $balance);
            }
        }

        return $balances;
    }

    /**
     * The accumulated deficit (a debit balance on 3901) brought into the year.
     */
    private function priorLoss(FiscalYear $fiscalYear): Money
    {
        $surplus = (int) DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', self::ACCUMULATED_SURPLUS)
            ->where('e.entry_date', '<', $fiscalYear->starts_on->toDateString())
            ->sum(DB::raw('l.credit_poisha - l.debit_poisha'));

        return $surplus < 0 ? Money::ofPoisha(-$surplus) : Money::zero();
    }
}
