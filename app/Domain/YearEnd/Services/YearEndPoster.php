<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Data\YearEndFigures;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * W8 step 7: on the last day of the year, (1) the closing JV moves every income and expense
 * balance into 3901, (2) the appropriation JV moves the profit (less the s.34(4) offset, which
 * stays in 3901 against the deficit) to the reserve, the funds and each member's dividend in 2201;
 * then the year is closed and the next one opened. Called inside ApproveYearEnd's transaction.
 */
final class YearEndPoster
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly CloseFiscalYear $closeYear,
        private readonly OpenFiscalYear $openYear,
    ) {}

    public function post(User $actor, YearEnd $yearEnd, FiscalYear $fiscalYear, YearEndFigures $figures): void
    {
        $date = $fiscalYear->ends_on;
        $closing = $this->postClosing($actor, $yearEnd, $fiscalYear, $figures, $date);
        $appropriation = $this->postAppropriation($actor, $yearEnd, $fiscalYear, $figures, $date);

        foreach ($figures->dividends as $memberId => $amount) {
            if ($amount->isPositive()) {
                $yearEnd->dividendLines()->create([
                    'member_id' => $memberId,
                    'share_months' => $figures->shareMonths[$memberId],
                    'amount_poisha' => $amount,
                    'status' => DividendStatus::Unpaid,
                ]);
            }
        }

        $yearEnd->update([
            'status' => YearEndStatus::Posted,
            'closing_journal_entry_id' => $closing,
            'appropriation_journal_entry_id' => $appropriation,
            'posted_by' => $actor->id,
            'posted_at' => CarbonImmutable::now(),
        ]);

        ($this->closeYear)($actor, $fiscalYear);

        if (FiscalYear::query()->where('start_year', $fiscalYear->start_year + 1)->doesntExist()) {
            // Opening a year is an accountant's job; the approving accountant opens it.
            ($this->openYear)(User::query()->findOrFail($yearEnd->accountant_approved_by), $fiscalYear->start_year + 1);
        }
    }

    private function postClosing(User $actor, YearEnd $yearEnd, FiscalYear $fiscalYear, YearEndFigures $figures, CarbonImmutable $date): ?int
    {
        $lines = [];

        foreach ($figures->accountBalances as $accountId => $balance) {
            $lines[] = $balance->isPositive()
                ? JournalLineData::debit($accountId, $balance)
                : JournalLineData::credit($accountId, $balance->negated());
        }

        $surplus = $this->accounts->byCode(YearEndCalculator::ACCUMULATED_SURPLUS);

        if ($figures->netProfit->isPositive()) {
            $lines[] = JournalLineData::credit($surplus, $figures->netProfit);
        } elseif ($figures->netProfit->isNegative()) {
            $lines[] = JournalLineData::debit($surplus, $figures->netProfit->negated());
        }

        if ($lines === []) {
            return null; // a year with no income or expense lines has nothing to close
        }

        return ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Journal,
            entryDate: $date,
            narration: __('year_end.narration.closing', ['code' => $fiscalYear->code]),
            lines: $lines,
            source: $yearEnd,
        ), allowInactiveAccounts: true)->id;
    }

    private function postAppropriation(User $actor, YearEnd $yearEnd, FiscalYear $fiscalYear, YearEndFigures $figures, CarbonImmutable $date): ?int
    {
        if (! $figures->isProfit()) {
            return null;
        }

        $distributed = $figures->netProfit->minus($figures->lossOffset);

        if (! $distributed->isPositive()) {
            return null;
        }

        $lines = [JournalLineData::debit($this->accounts->byCode(YearEndCalculator::ACCUMULATED_SURPLUS), $distributed)];

        foreach (AppropriationRates::ACCOUNTS as $key => $code) {
            $amount = $figures->appropriation[$key];

            if ($amount->isPositive()) {
                $lines[] = JournalLineData::credit($this->accounts->byCode($code), $amount);
            }
        }

        $dividendPayable = $this->accounts->byCode(AccountCode::DIVIDEND_PAYABLE);

        foreach ($figures->dividends as $memberId => $amount) {
            if ($amount->isPositive()) {
                $lines[] = JournalLineData::credit($dividendPayable, $amount, $memberId);
            }
        }

        return ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Journal,
            entryDate: $date,
            narration: __('year_end.narration.appropriation', ['code' => $fiscalYear->code]),
            lines: $lines,
            source: $yearEnd,
        ))->id;
    }
}
