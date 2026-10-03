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

/**
 * The maker withdraws their own pending expense (e.g. entered twice).
 */
final class CancelExpense
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Expense $expense): Expense
    {
        return $this->causer->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $expense): Expense {
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExpenseStatus::Pending) {
                throw DomainRuleViolation::because('expenses.errors.not_pending', ['number' => $locked->expense_no]);
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->update(['status' => ExpenseStatus::Cancelled]);

            return $locked;
        }, attempts: 3));
    }
}
