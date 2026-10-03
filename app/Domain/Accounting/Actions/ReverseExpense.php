<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an approved expense with a mirror voucher (the money came back, or it was entered wrongly).
 */
final class ReverseExpense
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Expense $expense, string $reason): Expense
    {
        if (mb_strlen(trim($reason)) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $expense, $reason): Expense {
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExpenseStatus::Approved) {
                throw DomainRuleViolation::because('expenses.errors.not_approved', ['number' => $locked->expense_no]);
            }

            Gate::forUser($actor)->authorize('reverse', $locked);

            $reversal = ($this->reverseJournal)(
                $actor,
                JournalEntry::query()->findOrFail($locked->journal_entry_id),
                $reason,
                onBehalfOfOwner: true,
            );

            $locked->update([
                'status' => ExpenseStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::now(),
                'reversal_reason' => trim($reason),
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
