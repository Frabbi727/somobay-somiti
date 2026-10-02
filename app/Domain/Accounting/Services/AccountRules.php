<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

/**
 * Validation shared by CreateAccount and UpdateAccount.
 */
final class AccountRules
{
    public function assertValid(AccountData $data, ?Account $existing = null): void
    {
        if (preg_match('/^[1-9]\d{3}$/', $data->code) !== 1) {
            throw DomainRuleViolation::because('accounting.errors.code_format');
        }

        if (! str_starts_with($data->code, $data->type->codePrefix())) {
            throw DomainRuleViolation::because('accounting.errors.code_prefix', [
                'prefix' => $data->type->codePrefix(),
                'type' => $data->type->getLabel(),
            ]);
        }

        if ($data->nameEn === '' || $data->nameBn === '') {
            throw DomainRuleViolation::because('accounting.errors.names_required');
        }

        $duplicate = Account::withTrashed()
            ->where('code', $data->code)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing?->getKey()))
            ->exists();

        if ($duplicate) {
            throw DomainRuleViolation::because('accounting.errors.code_taken', ['code' => $data->code]);
        }
    }
}
