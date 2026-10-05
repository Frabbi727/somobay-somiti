<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domain\Contributions\Models\Payment;
use App\Http\Api\ApiValue;

/**
 * A payment as the app lists it (dashboard "recent payments" and the payments list).
 */
final class PaymentSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'received_on' => ApiValue::date($payment->received_on),
            'method' => ApiValue::enum($payment->method),
            'trx_id' => $payment->trx_id,
            'amount' => ApiValue::money($payment->amount_poisha),
            'status' => ApiValue::enum($payment->status),
        ];
    }
}
