<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Reports\ReceiptDocument;
use Illuminate\Http\Response;

/**
 * Serves a payment receipt PDF behind a signed URL (§1.7: signed receipt URLs).
 */
final class ReceiptController extends Controller
{
    public function __invoke(Payment $payment, ReceiptDocument $document): Response
    {
        abort_unless(in_array($payment->status, [PaymentStatus::Approved, PaymentStatus::Reversed], true), 404);

        return response($document->pdf($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->filename($payment).'"',
        ]);
    }
}
