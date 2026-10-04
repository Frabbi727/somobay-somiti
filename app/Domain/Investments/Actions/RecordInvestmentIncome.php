<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentIncome;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W7: profit received by receipt voucher — Dr bank/cash (net), Dr 5106 (tax deducted at source),
 * Cr 4201 (gross). Also allowed after closure, for interest credited late.
 */
final class RecordInvestmentIncome
{
    public const string TAX_DEDUCTED = '5106';

    public const string INVESTMENT_PROFIT = '4201';

    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Investment $investment, Money $gross, Money $taxDeducted, CarbonImmutable $receivedOn, PaymentMethod $into, ?string $reference = null): InvestmentIncome
    {
        return $this->causer->withCauser($actor, fn (): InvestmentIncome => DB::transaction(function () use ($actor, $investment, $gross, $taxDeducted, $receivedOn, $into, $reference): InvestmentIncome {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('recordIncome', $locked);

            return $this->post($actor, $locked, $gross, $taxDeducted, $receivedOn, $into, $reference);
        }, attempts: 3));
    }

    /**
     * Posts the income for an investment already locked by the caller (also used by CloseInvestment).
     */
    public function post(User $actor, Investment $investment, Money $gross, Money $taxDeducted, CarbonImmutable $receivedOn, PaymentMethod $into, ?string $reference): InvestmentIncome
    {
        if (! $gross->isPositive() || $taxDeducted->isNegative() || ! $taxDeducted->isLessThan($gross)) {
            throw DomainRuleViolation::because('investments.errors.income_amounts');
        }

        if ($receivedOn->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay()) || $receivedOn->isBefore($investment->invested_on)) {
            throw DomainRuleViolation::because('investments.errors.income_date');
        }

        $lines = [JournalLineData::debit($this->accounts->byCode($into->accountCode()), $gross->minus($taxDeducted))];

        if ($taxDeducted->isPositive()) {
            $lines[] = JournalLineData::debit($this->accounts->byCode(self::TAX_DEDUCTED), $taxDeducted);
        }

        $lines[] = JournalLineData::credit($this->accounts->byCode(self::INVESTMENT_PROFIT), $gross);

        $entry = ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Receipt,
            entryDate: $receivedOn,
            narration: trim(sprintf('%s: %s %s', $investment->investment_no, __('investments.income.narration'), $reference ?? '')),
            lines: $lines,
            source: $investment,
        ));

        return $investment->income()->create([
            'received_on' => $receivedOn->toDateString(),
            'received_into' => $into,
            'gross_poisha' => $gross,
            'tax_deducted_poisha' => $taxDeducted,
            'reference' => $reference,
            'journal_entry_id' => $entry->id,
            'created_by' => $actor->id,
        ]);
    }
}
