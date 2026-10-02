<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Inactive accounts keep their history but reject new postings.
 */
final class SetAccountActive
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Account $account, bool $active): Account
    {
        Gate::forUser($actor)->authorize('update', $account);

        return $this->causer->withCauser($actor, fn (): Account => DB::transaction(function () use ($account, $active): Account {
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $locked->is_active = $active;
            $locked->save();

            return $locked;
        }, attempts: 3));
    }
}
