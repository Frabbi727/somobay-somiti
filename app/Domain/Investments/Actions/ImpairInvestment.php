<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Investments\Enums\InvestmentEntryKind;
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
 * Writes an investment down (e.g. a failed issuer) by journal voucher: Dr 5201 / Cr 13xx. The
 * book value never goes below zero. President only, with a reason.
 */
final class ImpairInvestment
{
    public const string IMPAIRMENT_LOSS = '5201';

    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Investment $investment, Money $amount, string $reason, CarbonImmutable $on): Investment
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment, $amount, $reason, $on): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('impair', $locked);

            if (! $amount->isPositive() || $amount->isGreaterThan($locked->bookValue())) {
                throw DomainRuleViolation::because('investments.errors.impairment_amount', ['book' => $locked->bookValue()->format(app()->getLocale())]);
            }

            if ($on->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay()) || $on->isBefore($locked->invested_on)) {
                throw DomainRuleViolation::because('investments.errors.income_date');
            }

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Journal,
                entryDate: $on,
                narration: $locked->investment_no.': '.__('investments.impairment.narration'),
                lines: [
                    JournalLineData::debit($this->accounts->byCode(self::IMPAIRMENT_LOSS), $amount),
                    JournalLineData::credit(Account::query()->findOrFail($locked->account_id), $amount),
                ],
                source: $locked,
                reason: $reason,
            ));

            $locked->ledger()->create([
                'kind' => InvestmentEntryKind::Impairment,
                'delta_poisha' => $amount->negated(),
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
