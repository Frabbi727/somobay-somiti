<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an added amount approved by mistake: a mirror voucher brings the money back and a
 * "reversal" register entry takes it off the investment. Refused once the investment is closed,
 * or when a write-down has left less book value than the amount being taken back.
 */
final class ReverseInvestmentTopUp
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, InvestmentTopUp $topUp, string $reason): InvestmentTopUp
    {
        if (mb_strlen(trim($reason)) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): InvestmentTopUp => DB::transaction(function () use ($actor, $topUp, $reason): InvestmentTopUp {
            $investment = Investment::query()->whereKey($topUp->investment_id)->lockForUpdate()->firstOrFail();
            $locked = InvestmentTopUp::query()->whereKey($topUp->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentTopUpStatus::Approved) {
                throw DomainRuleViolation::because('investments.errors.top_up_not_approved', ['number' => $investment->investment_no]);
            }

            if ($locked->approved_by === $actor->id) {
                throw DomainRuleViolation::because('investments.errors.reverse_own_approval');
            }

            Gate::forUser($actor)->authorize('reverse', $locked);

            if ($investment->status !== InvestmentStatus::Active) {
                throw DomainRuleViolation::because('investments.errors.reverse_not_active', ['number' => $investment->investment_no]);
            }

            if ($investment->bookValue()->isLessThan($locked->amount_poisha)) {
                throw DomainRuleViolation::because('investments.errors.reverse_has_activity', ['number' => $investment->investment_no]);
            }

            $reversal = ($this->reverseJournal)($actor, JournalEntry::query()->findOrFail($locked->journal_entry_id), $reason, onBehalfOfOwner: true);

            $investment->ledger()->create([
                'kind' => InvestmentEntryKind::Reversal,
                'delta_poisha' => $locked->amount_poisha->negated(),
                'journal_entry_id' => $reversal->id,
                'created_by' => $actor->id,
            ]);

            $locked->update([
                'status' => InvestmentTopUpStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::now(),
                'reversal_reason' => trim($reason),
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            $this->notifier->outcome($locked, 'reversed', [$locked->recorded_by, $locked->approved_by]);

            return $locked->setRelation('investment', $investment);
        }, attempts: 3));
    }
}
