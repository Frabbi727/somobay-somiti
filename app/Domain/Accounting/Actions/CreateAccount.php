<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\AccountRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class CreateAccount
{
    public function __construct(
        private readonly AccountRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, AccountData $data): Account
    {
        Gate::forUser($actor)->authorize('create', Account::class);

        return $this->causer->withCauser($actor, fn (): Account => DB::transaction(function () use ($data): Account {
            $this->rules->assertValid($data);

            return Account::query()->create([...$data->toAttributes(), 'is_active' => true]);
        }, attempts: 3));
    }
}
