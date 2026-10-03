<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Support\Money\Money;
use App\Support\Pdf\PdfRenderer;
use Illuminate\Support\Facades\URL;

/**
 * The money receipt for an approved payment: what it settled, what was held as advance,
 * and the member's "paid through" month.
 */
final class ReceiptDocument
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly PaidThroughCalculator $paidThrough,
    ) {}

    public function pdf(Payment $payment): string
    {
        $payment->loadMissing(['member', 'journalEntry', 'allocations.due', 'recorder', 'approver']);

        $held = Money::sum($payment->advanceEntries()
            ->where('kind', AdvanceEntryKind::PaymentSurplus)
            ->get()
            ->map(fn ($entry): Money => $entry->delta_poisha));

        return $this->pdf->render('reports.pdf.receipt', [
            'payment' => $payment,
            'held' => $held,
            'paidThrough' => $this->paidThrough->for($payment->member),
            'estimate' => $this->paidThrough->estimatedMonths($payment->member),
            'heading' => __('payments.receipt.title'),
        ], 'P', __('payments.receipt.title'));
    }

    public function filename(Payment $payment): string
    {
        return 'receipt-'.($payment->journalEntry->voucher_no ?? $payment->id).'.pdf';
    }

    /**
     * A link that works without signing in, valid for a week (for staff printouts and the portal).
     */
    public static function signedUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('receipts.show', now()->addDays(7), ['payment' => $payment->id]);
    }
}
