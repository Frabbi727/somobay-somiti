<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Checker: posts the expense as a payment voucher (Dr expense / Cr cash, bank or wallet) dated
 * the day it was spent. The paying account must hold the money.
 */
final class ApproveExpense
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Expense $expense): Expense
    {
        return $this->causer->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $expense): Expense {
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExpenseStatus::Pending) {
                throw DomainRuleViolation::because('expenses.errors.not_pending', ['number' => $locked->expense_no]);
            }

            Gate::forUser($actor)->authorize('approve', $locked);

            $expenseAccount = Account::query()->findOrFail($locked->account_id);
            $source = $this->accounts->byCode($locked->paid_from->accountCode());

            $this->funds->assertCovers($source, $locked->amount_poisha);

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Payment,
                entryDate: $locked->spent_on,
                narration: trim($locked->expense_no.': '.$locked->description.($locked->payee === null ? '' : ' — '.$locked->payee)),
                lines: [
                    JournalLineData::debit($expenseAccount, $locked->amount_poisha),
                    JournalLineData::credit($source, $locked->amount_poisha),
                ],
                source: $locked,
            ));

            $locked->update([
                'status' => ExpenseStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
                'journal_entry_id' => $entry->id,
            ]);

            return $locked->setRelation('journalEntry', $entry);
        }, attempts: 3));
    }
}
