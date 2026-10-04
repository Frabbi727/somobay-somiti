<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Enums;

use Filament\Support\Contracts\HasLabel;

enum AdvanceEntryKind: string implements HasLabel
{
    case PaymentSurplus = 'payment_surplus';
    case AppliedToDue = 'applied_to_due';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case FeeWaiver = 'fee_waiver';
    case ExitTransfer = 'exit_transfer';
    case ExitSettlement = 'exit_settlement';

    public function getLabel(): string
    {
        return __('payments.advance_kind.'.$this->value);
    }
}
