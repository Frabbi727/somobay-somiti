<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\DuePosting;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an approved payment (§5.4 cascade). If the advance it created has already been used,
 * those advance applications are reversed newest first (their dues reopen) until taking the
 * payment's surplus back cannot drive 2111 below zero. Then the payment's own receipt voucher is
 * reversed and its dues reopen. Every step is a reversing journal entry; nothing is deleted.
 */
final class ReversePayment
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly AdvanceLedger $advances,
        private readonly DuePosting $posting,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Payment $payment, string $reason): Payment
    {
        if (mb_strlen(trim($reason)) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('payments.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): Payment => DB::transaction(function () use ($actor, $payment, $reason): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reverse', $locked);

            if ($locked->status !== PaymentStatus::Approved || $locked->journal_entry_id === null) {
                throw DomainRuleViolation::because('payments.errors.not_reversible');
            }

            $member = Member::query()->whereKey($locked->member_id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);

            $surplus = AdvanceLedgerEntry::query()
                ->where('payment_id', $locked->id)
                ->where('kind', AdvanceEntryKind::PaymentSurplus)
                ->first();

            if ($surplus !== null) {
                $this->releaseDependentApplications($actor, $member, $surplus, $reason);
            }

            $reversal = ($this->reverseJournal)($actor, JournalEntry::query()->findOrFail($locked->journal_entry_id), $reason);

            foreach ($locked->allocations()->get() as $allocation) {
                $this->posting->unsettle(Due::query()->lockForUpdate()->findOrFail($allocation->due_id), $allocation->amount_poisha);
            }

            if ($surplus !== null) {
                $this->advances->append($member->id, AdvanceEntryKind::Reversal, $surplus->delta_poisha->negated(), [
                    'payment_id' => $locked->id,
                    'journal_entry_id' => $reversal->id,
                    'reverses_entry_id' => $surplus->id,
                    'created_by' => $actor->id,
                ]);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::now(),
                'reversal_reason' => $reason,
                'reversal_journal_entry_id' => $reversal->id,
            ])->save();

            return $locked;
        }, attempts: 3));
    }

    /**
     * Reverses advance applications, newest voucher first, until the balance covers the surplus.
     */
    private function releaseDependentApplications(User $actor, Member $member, AdvanceLedgerEntry $surplus, string $reason): void
    {
        $needed = $surplus->delta_poisha;

        while ($this->advances->balance($member->id)->isLessThan($needed)) {
            $latest = AdvanceLedgerEntry::query()
                ->where('member_id', $member->id)
                ->where('kind', AdvanceEntryKind::AppliedToDue)
                ->whereNotExists(fn ($query) => $query->from('advance_ledger_entries as r')->whereColumn('r.reverses_entry_id', 'advance_ledger_entries.id'))
                ->orderByDesc('id')
                ->first();

            if ($latest === null || $latest->journal_entry_id === null) {
                throw DomainRuleViolation::because('payments.errors.advance_negative');
            }

            $reversal = ($this->reverseJournal)($actor, JournalEntry::query()->findOrFail($latest->journal_entry_id), $reason);

            $group = AdvanceLedgerEntry::query()
                ->where('journal_entry_id', $latest->journal_entry_id)
                ->where('kind', AdvanceEntryKind::AppliedToDue)
                ->orderByDesc('id')
                ->get();

            foreach ($group as $application) {
                $due = Due::query()->lockForUpdate()->findOrFail($application->due_id);
                $this->posting->unsettle($due, $application->delta_poisha->negated());

                $this->advances->append($member->id, AdvanceEntryKind::Reversal, $application->delta_poisha->negated(), [
                    'due_id' => $due->id,
                    'journal_entry_id' => $reversal->id,
                    'reverses_entry_id' => $application->id,
                    'created_by' => $actor->id,
                ]);
            }
        }
    }
}
