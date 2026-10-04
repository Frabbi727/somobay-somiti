<?php

declare(strict_types=1);

namespace App\Domain\Exits\Actions;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Contributions\Actions\ApplyAdvance;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\DuePosting;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Exits\Services\ExitCalculator;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Services\RequiredResolutions;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Filament\Support\Display;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W9 steps 3–5 (BR-22), in one transaction, after the exit month has ended:
 *  1. dues after the exit month are released (anything paid on them returns to the advance) and cancelled;
 *     unpaid deposits are cancelled (savings never made, not a debt);
 *  2. the member's savings move into their advance, and the advance engine settles what they still
 *     owe (service charges, registration, late fees) — oldest first, with the usual income postings;
 *  3. the settlement JV moves the remaining advance and any unpaid dividends, less the exit fee (4131),
 *     to 2301 Exit Settlements Payable; the member becomes "exited".
 * If the member owes more than they hold, nothing is posted and the shortfall is reported.
 */
final class ApproveExit
{
    public const string EXIT_FEE_INCOME = '4131';

    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly AdvanceLedger $advances,
        private readonly DuePosting $duePosting,
        private readonly ApplyAdvance $applyAdvance,
        private readonly ExitCalculator $calculator,
        private readonly RequiredResolutions $resolutions,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, MemberExit $exit): MemberExit
    {
        return $this->causer->withCauser($actor, fn (): MemberExit => DB::transaction(function () use ($actor, $exit): MemberExit {
            $member = Member::query()->whereKey($exit->member_id)->lockForUpdate()->firstOrFail();
            $locked = MemberExit::query()->whereKey($exit->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $locked);

            $this->resolutions->assertSatisfied(ResolutionSubject::Exit, $locked->resolution_id);

            if (! $locked->exit_month->isBefore(YearMonth::current())) {
                throw DomainRuleViolation::because('exits.errors.month_not_over', ['month' => Display::yearMonth($locked->exit_month)]);
            }

            if (RequestExit::hasPendingPayments($member->id)) {
                throw DomainRuleViolation::because('exits.errors.pending_payments');
            }

            $today = CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay();
            $preview = $this->calculator->preview($member, $locked->exit_month, $locked->exit_fee_poisha);

            $released = $this->releaseDuesAfter($actor, $member, $locked->exit_month, $today);
            $this->cancelUnpaidDeposits($member, $locked->exit_month);
            $this->moveSavingsToAdvance($actor, $member, $today);

            ($this->applyAdvance)([$member->id], $actor, $today);

            $owed = $this->calculator->receivables($member->id, $locked->exit_month);

            if ($owed->isPositive()) {
                throw DomainRuleViolation::because('exits.errors.shortfall', ['amount' => Display::money($owed)]);
            }

            $net = $this->settle($actor, $member, $locked, $today);

            $member->forceFill([
                'status' => MemberStatus::Exited,
                'deactivated_at' => $member->deactivated_at ?? CarbonImmutable::now(),
                'deactivation_reason' => $member->deactivation_reason ?? $locked->reason,
            ])->save();

            $locked->update([
                'status' => ExitStatus::Approved,
                'savings_poisha' => $preview->savings,
                'advance_poisha' => $preview->advance,
                'dividends_poisha' => $preview->dividends,
                'receivables_poisha' => $preview->receivables,
                'released_poisha' => $released,
                'net_poisha' => $net['net'],
                'settlement_journal_entry_id' => $net['entry'],
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        }, attempts: 3));
    }

    /**
     * Prepaid or advance-paid dues for months after the exit go back to the advance.
     */
    private function releaseDuesAfter(User $actor, Member $member, YearMonth $exitMonth, CarbonImmutable $today): Money
    {
        $dues = Due::query()
            ->where('member_id', $member->id)
            ->where('month', '>', $exitMonth->toDateString())
            ->whereIn('status', [DueStatus::Open, DueStatus::Settled])
            ->orderBy('month')->orderBy('id')
            ->lockForUpdate()
            ->get();

        $paid = $dues->filter(fn (Due $due): bool => $due->paid_poisha->isPositive());
        $total = Money::sum($paid->map(fn (Due $due): Money => $due->paid_poisha));

        if ($total->isPositive()) {
            $lines = [];

            foreach ($paid as $due) {
                $account = $this->accounts->byCode($this->duePosting->accountCodeFor($due->type));
                $lines[] = JournalLineData::debit($account, $due->paid_poisha, $account->requires_member ? $member->id : null);
            }

            $lines[] = JournalLineData::credit($this->accounts->byCode(AccountCode::MEMBER_ADVANCE), $total, $member->id);

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Journal,
                entryDate: $today,
                narration: __('exits.narration.release', ['member' => $member->member_no]),
                lines: $lines,
                source: $member,
            ));

            foreach ($paid as $due) {
                $this->advances->append($member->id, AdvanceEntryKind::Reversal, $due->paid_poisha, [
                    'due_id' => $due->id,
                    'journal_entry_id' => $entry->id,
                    'created_by' => $actor->id,
                ]);
                $this->duePosting->unsettle($due, $due->paid_poisha);
            }
        }

        foreach ($dues as $due) {
            $due->forceFill(['status' => DueStatus::Cancelled, 'closed_at' => CarbonImmutable::now()])->save();
        }

        return $total;
    }

    private function cancelUnpaidDeposits(Member $member, YearMonth $exitMonth): void
    {
        Due::query()
            ->where('member_id', $member->id)
            ->where('month', '<=', $exitMonth->toDateString())
            ->where('type', DueType::Deposit)
            ->where('status', DueStatus::Open)
            ->lockForUpdate()
            ->get()
            ->each(fn (Due $due) => $due->forceFill(['status' => DueStatus::Cancelled, 'closed_at' => CarbonImmutable::now()])->save());
    }

    private function moveSavingsToAdvance(User $actor, Member $member, CarbonImmutable $today): void
    {
        $savings = $this->calculator->memberBalance($member->id, AccountCode::MEMBER_SAVINGS);

        if (! $savings->isPositive()) {
            return;
        }

        $entry = ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Journal,
            entryDate: $today,
            narration: __('exits.narration.savings', ['member' => $member->member_no]),
            lines: [
                JournalLineData::debit($this->accounts->byCode(AccountCode::MEMBER_SAVINGS), $savings, $member->id),
                JournalLineData::credit($this->accounts->byCode(AccountCode::MEMBER_ADVANCE), $savings, $member->id),
            ],
            source: $member,
        ));

        $this->advances->append($member->id, AdvanceEntryKind::ExitTransfer, $savings, [
            'journal_entry_id' => $entry->id,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @return array{net: Money, entry: int|null}
     */
    private function settle(User $actor, Member $member, MemberExit $exit, CarbonImmutable $today): array
    {
        $advance = $this->advances->balance($member->id);
        $dividendLines = DividendLine::query()->where('member_id', $member->id)->where('status', DividendStatus::Unpaid)->lockForUpdate()->get();
        $dividends = Money::sum($dividendLines->map(fn (DividendLine $line): Money => $line->amount_poisha));
        $fee = $exit->exit_fee_poisha;
        $net = $advance->plus($dividends)->minus($fee);

        if ($net->isNegative()) {
            throw DomainRuleViolation::because('exits.errors.fee_exceeds', ['amount' => Display::money($advance->plus($dividends))]);
        }

        $lines = [];

        if ($advance->isPositive()) {
            $lines[] = JournalLineData::debit($this->accounts->byCode(AccountCode::MEMBER_ADVANCE), $advance, $member->id);
        }

        if ($dividends->isPositive()) {
            $lines[] = JournalLineData::debit($this->accounts->byCode(AccountCode::DIVIDEND_PAYABLE), $dividends, $member->id);
        }

        if ($fee->isPositive()) {
            $lines[] = JournalLineData::credit($this->accounts->byCode(self::EXIT_FEE_INCOME), $fee);
        }

        if ($net->isPositive()) {
            $lines[] = JournalLineData::credit($this->accounts->byCode(AccountCode::EXIT_PAYABLE), $net, $member->id);
        }

        if ($lines === []) {
            return ['net' => $net, 'entry' => null];
        }

        $entry = ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Journal,
            entryDate: $today,
            narration: __('exits.narration.settlement', ['member' => $member->member_no, 'number' => $exit->exit_no]),
            lines: $lines,
            source: $exit,
        ));

        if ($advance->isPositive()) {
            $this->advances->append($member->id, AdvanceEntryKind::ExitSettlement, $advance->negated(), [
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);
        }

        foreach ($dividendLines as $line) {
            $line->update([
                'status' => DividendStatus::Paid,
                'settled_via' => 'exit',
                'settlement_journal_entry_id' => $entry->id,
                'settled_by' => $actor->id,
                'settled_at' => CarbonImmutable::now(),
            ]);
        }

        return ['net' => $net, 'entry' => $entry->id];
    }
}
