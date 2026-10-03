<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Events;

use App\Domain\Contributions\Models\Payment;

/**
 * Fired inside the approval transaction; the SMS module (Phase 6) sends the receipt message from here.
 */
final readonly class PaymentApproved
{
    public function __construct(public Payment $payment) {}
}
