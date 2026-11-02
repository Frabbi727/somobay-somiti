<?php

declare(strict_types=1);

namespace App\Domain\Exits\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Exits\Models\MemberExitPayoutReversal;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an exit payout made by mistake (wrong account, wrong payee, never handed over): a mirror
 * voucher brings the money back to 2301, a reversal row keeps who paid, who reversed and why, and
 * the exit returns to "approved, waiting for payout" so it can be paid again correctly. The
 * settlement itself (savings, advance, dividends, fee) is not touched.
 */
final class ReverseExitPayout
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, MemberExit $exit, string $reason): MemberExit
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): MemberExit => DB::transaction(function () use ($actor, $exit, $reason): MemberExit {
            $locked = MemberExit::query()->whereKey($exit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExitStatus::Paid || $locked->payout_journal_entry_id === null || $locked->paid_by === null) {
                throw DomainRuleViolation::because('exits.errors.nothing_to_reverse', ['number' => $locked->exit_no]);
            }

            if ($locked->paid_by === $actor->id) {
                throw DomainRuleViolation::because('exits.errors.reverse_own_payout');
            }

            Gate::forUser($actor)->authorize('reversePayout', $locked);

            $payout = JournalEntry::query()->findOrFail($locked->payout_journal_entry_id);
            $reversal = ($this->reverseJournal)($actor, $payout, $reason, onBehalfOfOwner: true);

            MemberExitPayoutReversal::query()->create([
                'member_exit_id' => $locked->id,
                'amount_poisha' => $locked->net_poisha,
                'payout_journal_entry_id' => $payout->id,
                'reversal_journal_entry_id' => $reversal->id,
                'paid_by' => $locked->paid_by,
                'reversed_by' => $actor->id,
                'reason' => $reason,
            ]);

            $paidBy = $locked->paid_by;

            $locked->update([
                'status' => ExitStatus::Approved,
                'paid_by' => null,
                'paid_at' => null,
                'payout_journal_entry_id' => null,
            ]);

            $this->notifier->outcome($locked, 'payout_reversed', [$paidBy, $locked->approved_by]);
            $this->notifier->waiting($locked, 'pay', 'to_pay');

            return $locked;
        }, attempts: 3));
    }
}
