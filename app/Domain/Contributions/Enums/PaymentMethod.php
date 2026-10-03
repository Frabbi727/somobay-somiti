<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Enums;

use App\Domain\Accounting\AccountCode;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PaymentMethod: string implements HasIcon, HasLabel
{
    case Cash = 'cash';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Bank = 'bank';

    /**
     * The asset account the money lands in.
     */
    public function accountCode(): string
    {
        return match ($this) {
            self::Cash => AccountCode::CASH,
            self::Bkash => AccountCode::BKASH,
            self::Nagad => AccountCode::NAGAD,
            self::Bank => AccountCode::BANK,
        };
    }

    public function needsTrxId(): bool
    {
        return $this !== self::Cash;
    }

    public function getLabel(): string
    {
        return __('payments.method.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Cash => Heroicon::OutlinedBanknotes,
            self::Bkash, self::Nagad => Heroicon::OutlinedDevicePhoneMobile,
            self::Bank => Heroicon::OutlinedBuildingLibrary,
        };
    }
}
