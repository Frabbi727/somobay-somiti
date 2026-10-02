<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Soft-deletes an account that was never posted to (§7.4). Accounts with journal lines can
 * only be deactivated; the journal_lines foreign key also blocks any hard delete.
 */
final class DeleteAccount
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Account $account): void
    {
        $this->causer->withCauser($actor, fn () => DB::transaction(function () use ($actor, $account): void {
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasJournalLines()) {
                throw DomainRuleViolation::because('accounting.errors.account_has_lines', ['code' => $locked->code]);
            }

            Gate::forUser($actor)->authorize('delete', $locked);

            $locked->delete();
        }, attempts: 3));
    }
}
