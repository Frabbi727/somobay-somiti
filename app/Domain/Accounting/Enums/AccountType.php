<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum AccountType: string implements HasColor, HasIcon, HasLabel
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * Assets and expenses grow with debits; liabilities, equity and income grow with credits.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }

    /**
     * The leading digit every account code of this type must start with.
     */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Asset => '1',
            self::Liability => '2',
            self::Equity => '3',
            self::Income => '4',
            self::Expense => '5',
        };
    }

    public function getLabel(): string
    {
        return __('accounting.account_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Asset => 'info',
            self::Liability => 'warning',
            self::Equity => 'primary',
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Asset => Heroicon::OutlinedBuildingLibrary,
            self::Liability => Heroicon::OutlinedScale,
            self::Equity => Heroicon::OutlinedShieldCheck,
            self::Income => Heroicon::OutlinedArrowTrendingUp,
            self::Expense => Heroicon::OutlinedArrowTrendingDown,
        };
    }
}
