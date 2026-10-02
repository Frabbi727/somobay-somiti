<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\AccountRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class UpdateAccount
{
    public function __construct(
        private readonly AccountRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * Names, flags and description may always change. Code and type are frozen once the
     * account has journal lines, because changing them would rewrite posted history.
     */
    public function __invoke(User $actor, Account $account, AccountData $data): Account
    {
        Gate::forUser($actor)->authorize('update', $account);

        return $this->causer->withCauser($actor, fn (): Account => DB::transaction(function () use ($account, $data): Account {
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $this->rules->assertValid($data, $locked);

            $identityChanged = $locked->code !== $data->code || $locked->type !== $data->type;

            if ($identityChanged && $locked->hasJournalLines()) {
                throw DomainRuleViolation::because('accounting.errors.identity_frozen', ['code' => $locked->code]);
            }

            if ($locked->hasJournalLines() && $locked->requires_member !== $data->requiresMember) {
                throw DomainRuleViolation::because('accounting.errors.member_flag_frozen', ['code' => $locked->code]);
            }

            $locked->fill($data->toAttributes())->save();

            return $locked;
        }, attempts: 3));
    }
}
