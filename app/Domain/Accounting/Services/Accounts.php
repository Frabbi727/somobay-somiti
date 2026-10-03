<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

/**
 * Looks up system accounts by code, once per request.
 */
final class Accounts
{
    public function byCode(string $code): Account
    {
        $accounts = once(fn () => Account::query()->get()->keyBy('code'));

        return $accounts->get($code) ?? throw DomainRuleViolation::because('accounting.errors.missing_account', ['code' => $code]);
    }
}
