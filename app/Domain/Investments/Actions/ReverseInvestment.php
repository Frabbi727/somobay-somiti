<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an investment approved by mistake: a mirror voucher brings the money back to bank or
 * cash, a "reversal" register entry takes the book value to zero, and the investment is marked
 * reversed with who, when and why. Only an investment with nothing else on it yet can be reversed:
 * added amounts are reversed first, pending ones cancelled or rejected, and one with profit, a
 * write-down or a sale recorded cannot be undone this way.
 */
final class ReverseInvestment
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, Investment $investment, string $reason): Investment
    {
        if (mb_strlen(trim($reason)) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment, $reason): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentStatus::Active) {
                throw DomainRuleViolation::because('investments.errors.reverse_not_active', ['number' => $locked->investment_no]);
            }

            if ($locked->approved_by === $actor->id) {
                throw DomainRuleViolation::because('investments.errors.reverse_own_approval');
            }

            Gate::forUser($actor)->authorize('reverse', $locked);

            if ($locked->topUps()->whereIn('status', [InvestmentTopUpStatus::Pending, InvestmentTopUpStatus::Approved])->exists()) {
                throw DomainRuleViolation::because('investments.errors.reverse_top_ups_first', ['number' => $locked->investment_no]);
            }

            if ($locked->income()->exists() || $locked->ledger()->whereIn('kind', [InvestmentEntryKind::Impairment->value, InvestmentEntryKind::Closure->value])->exists()) {
                throw DomainRuleViolation::because('investments.errors.reverse_has_activity', ['number' => $locked->investment_no]);
            }

            $book = $locked->bookValue();
            $reversal = ($this->reverseJournal)($actor, JournalEntry::query()->findOrFail($locked->journal_entry_id), $reason, onBehalfOfOwner: true);

            $locked->ledger()->create([
                'kind' => InvestmentEntryKind::Reversal,
                'delta_poisha' => $book->negated(),
                'journal_entry_id' => $reversal->id,
                'created_by' => $actor->id,
            ]);

            $locked->update([
                'status' => InvestmentStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::now(),
                'reversal_reason' => trim($reason),
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            $this->notifier->outcome($locked, 'reversed', [$locked->recorded_by, $locked->approved_by]);

            return $locked;
        }, attempts: 3));
    }
}
