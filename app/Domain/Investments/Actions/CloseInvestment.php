<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Maturity, encashment or sale. The sale price (or capital returned) less the selling expenses
 * paid out of it comes in by receipt voucher and the book value leaves 13xx. The difference is the
 * gain on sale (Cr 4211) or the loss on sale (Dr 5202), kept apart from rent and other profit
 * (4201). Final profit, if any, is recorded with it as investment income. The sale price, the
 * expenses and the gain or loss stay on the investment.
 */
final class CloseInvestment
{
    public const string GAIN_ON_SALE = '4211';

    public const string LOSS_ON_SALE = '5202';

    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly RecordInvestmentIncome $income,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(
        User $actor,
        Investment $investment,
        Money $salePrice,
        CarbonImmutable $on,
        PaymentMethod $into,
        ?Money $finalProfit = null,
        ?Money $taxDeducted = null,
        ?Money $saleExpenses = null,
    ): Investment {
        $saleExpenses ??= Money::zero();

        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment, $salePrice, $on, $into, $finalProfit, $taxDeducted, $saleExpenses): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('close', $locked);

            if ($salePrice->isNegative()) {
                throw DomainRuleViolation::because('investments.errors.capital_negative');
            }

            if ($saleExpenses->isNegative() || $saleExpenses->isGreaterThan($salePrice)) {
                throw DomainRuleViolation::because('investments.errors.sale_expenses');
            }

            if ($on->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay()) || $on->isBefore($locked->invested_on)) {
                throw DomainRuleViolation::because('investments.errors.income_date');
            }

            $book = $locked->bookValue();
            $received = $salePrice->minus($saleExpenses);
            $gain = $received->minus($book);
            $lines = [];

            if ($received->isPositive()) {
                $lines[] = JournalLineData::debit($this->accounts->byCode($into->accountCode()), $received);
            }

            if ($gain->isNegative()) {
                $lines[] = JournalLineData::debit($this->accounts->byCode(self::LOSS_ON_SALE), $gain->negated());
            }

            if ($book->isPositive()) {
                $lines[] = JournalLineData::credit(Account::query()->findOrFail($locked->account_id), $book);
            }

            if ($gain->isPositive()) {
                $lines[] = JournalLineData::credit($this->accounts->byCode(self::GAIN_ON_SALE), $gain);
            }

            if ($lines !== []) {
                $entry = ($this->post)($actor, new JournalEntryData(
                    type: $received->isPositive() ? VoucherType::Receipt : VoucherType::Journal,
                    entryDate: $on,
                    narration: $locked->investment_no.': '.__('investments.closure.narration'),
                    lines: $lines,
                    source: $locked,
                ));

                if ($book->isPositive()) {
                    $locked->ledger()->create([
                        'kind' => InvestmentEntryKind::Closure,
                        'delta_poisha' => $book->negated(),
                        'journal_entry_id' => $entry->id,
                        'created_by' => $actor->id,
                    ]);
                }
            }

            if ($finalProfit !== null && $finalProfit->isPositive()) {
                $this->income->post($actor, $locked, $finalProfit, $taxDeducted ?? Money::zero(), $on, $into, null);
            }

            $locked->update([
                'status' => InvestmentStatus::Closed,
                'closed_on' => $on->toDateString(),
                'sale_price_poisha' => $salePrice,
                'sale_expenses_poisha' => $saleExpenses,
                'sale_gain_poisha' => $gain,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
