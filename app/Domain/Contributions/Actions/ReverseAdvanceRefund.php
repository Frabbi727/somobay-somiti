<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an advance refund made by mistake (wrong member, wrong amount, money never handed over):
 * a mirror voucher brings the money back into 2111, and a reversal row in the advance ledger
 * (reverses_entry_id, unique) gives the member their advance back. The refund itself stays on
 * record. Someone other than whoever made the refund reverses it; an exited member's refunds are
 * never reversed, because the exit settlement already closed their advance.
 */
final class ReverseAdvanceRefund
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly AdvanceLedger $advances,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, AdvanceLedgerEntry $refund, string $reason): AdvanceLedgerEntry
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): AdvanceLedgerEntry => DB::transaction(function () use ($actor, $refund, $reason): AdvanceLedgerEntry {
            $member = Member::query()->whereKey($refund->member_id)->lockForUpdate()->firstOrFail();
            $locked = AdvanceLedgerEntry::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->kind !== AdvanceEntryKind::Refund || $locked->journal_entry_id === null || $locked->reversal()->exists()) {
                throw DomainRuleViolation::because('payments.errors.refund_not_reversible');
            }

            if ($locked->created_by === $actor->id) {
                throw DomainRuleViolation::because('payments.errors.reverse_own_refund');
            }

            Gate::forUser($actor)->authorize('reverseRefund', $locked);

            if ($member->status === MemberStatus::Exited) {
                throw DomainRuleViolation::because('payments.errors.refund_reverse_after_exit');
            }

            $voucher = JournalEntry::query()->findOrFail($locked->journal_entry_id);
            $reversal = ($this->reverseJournal)($actor, $voucher, $reason, onBehalfOfOwner: true);

            return $this->advances->append($member->id, AdvanceEntryKind::Reversal, $locked->delta_poisha->negated(), [
                'journal_entry_id' => $reversal->id,
                'reverses_entry_id' => $locked->id,
                'created_by' => $actor->id,
            ]);
        }, attempts: 3));
    }
}
