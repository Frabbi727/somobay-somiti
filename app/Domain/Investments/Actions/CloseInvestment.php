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
 * Maturity, encashment or sale: the capital returned comes in by receipt voucher and the book
 * value leaves 13xx; a difference is a gain (Cr 4201) or a loss (Dr 5201). Final profit, if any,
 * is recorded with it as investment income.
 */
final class CloseInvestment
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly RecordInvestmentIncome $income,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(
        User $actor,
        Investment $investment,
        Money $capitalReturned,
        CarbonImmutable $on,
        PaymentMethod $into,
        ?Money $finalProfit = null,
        ?Money $taxDeducted = null,
    ): Investment {
        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment, $capitalReturned, $on, $into, $finalProfit, $taxDeducted): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('close', $locked);

            if ($capitalReturned->isNegative()) {
                throw DomainRuleViolation::because('investments.errors.capital_negative');
            }

            if ($on->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay()) || $on->isBefore($locked->invested_on)) {
                throw DomainRuleViolation::because('investments.errors.income_date');
            }

            $book = $locked->bookValue();
            $lines = [];

            if ($capitalReturned->isPositive()) {
                $lines[] = JournalLineData::debit($this->accounts->byCode($into->accountCode()), $capitalReturned);
            }

            if ($capitalReturned->isLessThan($book)) {
                $lines[] = JournalLineData::debit($this->accounts->byCode(ImpairInvestment::IMPAIRMENT_LOSS), $book->minus($capitalReturned));
            }

            if ($book->isPositive()) {
                $lines[] = JournalLineData::credit(Account::query()->findOrFail($locked->account_id), $book);
            }

            if ($capitalReturned->isGreaterThan($book)) {
                $lines[] = JournalLineData::credit($this->accounts->byCode(RecordInvestmentIncome::INVESTMENT_PROFIT), $capitalReturned->minus($book));
            }

            if ($lines !== []) {
                $entry = ($this->post)($actor, new JournalEntryData(
                    type: $capitalReturned->isPositive() ? VoucherType::Receipt : VoucherType::Journal,
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

            $locked->update(['status' => InvestmentStatus::Closed, 'closed_on' => $on->toDateString()]);

            return $locked;
        }, attempts: 3));
    }
}
