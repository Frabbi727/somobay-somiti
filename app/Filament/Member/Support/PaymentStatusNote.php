<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;

/**
 * One line under a member's payment status saying what it means for them and what to do.
 */
final class PaymentStatusNote
{
    public static function for(Payment $payment): ?string
    {
        return match ($payment->status) {
            PaymentStatus::Pending => (string) __('portal.payment_note.pending'),
            PaymentStatus::Rejected => (string) __('portal.payment_note.rejected', ['reason' => (string) $payment->rejection_reason]),
            PaymentStatus::Reversed => (string) __('portal.payment_note.reversed', ['reason' => (string) $payment->reversal_reason]),
            default => null,
        };
    }
}
