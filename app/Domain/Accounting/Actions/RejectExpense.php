<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class RejectExpense
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Expense $expense, string $reason): Expense
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $expense, $reason): Expense {
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExpenseStatus::Pending) {
                throw DomainRuleViolation::because('expenses.errors.not_pending', ['number' => $locked->expense_no]);
            }

            Gate::forUser($actor)->authorize('reject', $locked);

            $locked->update(['status' => ExpenseStatus::Rejected, 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
