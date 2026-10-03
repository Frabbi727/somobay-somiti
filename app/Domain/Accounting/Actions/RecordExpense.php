<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records money spent as a pending expense (maker). Nothing is posted until a different user
 * approves it. The same idempotency key twice returns the first expense.
 */
final class RecordExpense
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, ExpenseData $data): Expense
    {
        Gate::forUser($actor)->authorize('create', Expense::class);

        $existing = Expense::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if ($existing !== null) {
            if ($existing->account_id !== $data->accountId || ! $existing->amount_poisha->equals($data->amount)) {
                throw DomainRuleViolation::because('expenses.errors.idempotency_conflict');
            }

            return $existing;
        }

        $this->assertValid($data);

        return $this->causer->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $data): Expense {
            $number = (int) DB::scalar("SELECT nextval('expense_no_seq')");

            return Expense::query()->create([
                'expense_no' => sprintf('E-%05d', $number),
                'account_id' => $data->accountId,
                'paid_from' => $data->paidFrom,
                'amount_poisha' => $data->amount,
                'spent_on' => $data->spentOn->toDateString(),
                'payee' => $data->payee,
                'reference' => $data->reference,
                'description' => $data->description,
                'attachment_path' => $data->attachmentPath,
                'status' => ExpenseStatus::Pending,
                'idempotency_key' => $data->idempotencyKey,
                'recorded_by' => $actor->id,
            ]);
        }, attempts: 3));
    }

    private function assertValid(ExpenseData $data): void
    {
        $account = Account::query()->find($data->accountId);

        if ($account === null || $account->type !== AccountType::Expense || ! $account->is_active) {
            throw DomainRuleViolation::because('expenses.errors.expense_account');
        }

        if (! $data->amount->isPositive()) {
            throw DomainRuleViolation::because('expenses.errors.amount_positive');
        }

        if (mb_strlen($data->description) < 3) {
            throw DomainRuleViolation::because('expenses.errors.description_required');
        }

        if ($data->spentOn->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
            throw DomainRuleViolation::because('expenses.errors.future_date');
        }
    }
}
