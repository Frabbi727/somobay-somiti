<?php

declare(strict_types=1);

namespace App\Domain\Investments\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Each kind of investment has its own 13xx control account, tied to the register.
 */
enum InvestmentType: string implements HasLabel
{
    case FixedDeposit = 'fixed_deposit';
    case SavingsCertificate = 'savings_certificate';
    case GovernmentSecurities = 'government_securities';
    case CompanySecurities = 'company_securities';
    case Cooperative = 'cooperative';
    case Other = 'other';

    public function accountCode(): string
    {
        return match ($this) {
            self::FixedDeposit => '1301',
            self::SavingsCertificate => '1302',
            self::GovernmentSecurities => '1303',
            self::CompanySecurities => '1304',
            self::Cooperative => '1305',
            self::Other => '1399',
        };
    }

    public static function fromAccountCode(string $code): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->accountCode() === $code) {
                return $type;
            }
        }

        return null;
    }

    public function getLabel(): string
    {
        return __('investments.type.'.$this->value);
    }
}
