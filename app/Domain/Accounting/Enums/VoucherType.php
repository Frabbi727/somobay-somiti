<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Voucher series (BR-18). Each type has its own gap-free numbering per fiscal year.
 */
enum VoucherType: string implements HasColor, HasIcon, HasLabel
{
    case Receipt = 'RV';
    case Payment = 'PV';
    case Journal = 'JV';
    case Contra = 'CV';

    /**
     * Types that staff may prepare by hand as drafts. Receipts and payments come from
     * the collections, expenses and exit modules.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Journal, self::Contra];
    }

    public function getLabel(): string
    {
        return __('journal.voucher_type.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Receipt => 'success',
            self::Payment => 'danger',
            self::Journal => 'info',
            self::Contra => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Receipt => Heroicon::OutlinedArrowDownTray,
            self::Payment => Heroicon::OutlinedArrowUpTray,
            self::Journal => Heroicon::OutlinedDocumentText,
            self::Contra => Heroicon::OutlinedArrowsRightLeft,
        };
    }
}
