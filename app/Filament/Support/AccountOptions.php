<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Collection;

/**
 * Account choices for voucher forms, loaded once per request.
 */
final class AccountOptions
{
    /**
     * @return array<int, string>
     */
    public static function active(): array
    {
        return self::accounts()
            ->filter(fn (Account $account): bool => $account->is_active)
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->displayName()])
            ->all();
    }

    public static function requiresMember(mixed $accountId): bool
    {
        return is_numeric($accountId) && self::accounts()->get((int) $accountId)?->requires_member === true;
    }

    /**
     * @return Collection<int, Account>
     */
    private static function accounts(): Collection
    {
        return once(fn (): Collection => Account::query()->orderBy('code')->get()->keyBy('id'));
    }
}
