<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class RestoreAccount
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Account $account): Account
    {
        Gate::forUser($actor)->authorize('restore', $account);

        return $this->causer->withCauser($actor, fn (): Account => DB::transaction(function () use ($account): Account {
            $locked = Account::withTrashed()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $locked->restore();

            return $locked;
        }, attempts: 3));
    }
}
