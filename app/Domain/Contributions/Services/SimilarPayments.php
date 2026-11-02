<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cash has no transaction id, so the same money can be entered twice by two cashiers or by one
 * cashier after a page reload. A pending or approved payment from the same member, for the same
 * amount, on the same day is probably that mistake. Only a warning: two genuine equal payments
 * on one day are possible, so the person entering or approving decides.
 */
final class SimilarPayments
{
    /**
     * @return Collection<int, Payment>
     */
    public function to(int $memberId, Money $amount, CarbonImmutable $receivedOn, ?int $exceptId = null): Collection
    {
        return Payment::query()
            ->where('member_id', $memberId)
            ->where('amount_poisha', $amount->poisha)
            ->whereDate('received_on', $receivedOn->toDateString())
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Approved])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function toPayment(Payment $payment): Collection
    {
        return $this->to($payment->member_id, $payment->amount_poisha, $payment->received_on, $payment->id);
    }
}
