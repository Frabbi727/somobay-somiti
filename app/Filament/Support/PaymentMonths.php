<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
use App\Domain\Contributions\Services\AllocationEngine;
use App\Domain\Contributions\Services\PaymentPreviewer;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * "Which months did this payment pay?" in words, for payment lists: "Jul – Sep 2026 + ৳ 500 to
 * advance". Needs allocations.due loaded. Months are known only once the payment is approved.
 */
final class PaymentMonths
{
    public static function describe(Payment $payment): string
    {
        if ($payment->status === PaymentStatus::Pending) {
            return __('payments.for_months.pending');
        }

        if (! in_array($payment->status, [PaymentStatus::Approved, PaymentStatus::Reversed], true)) {
            return '—';
        }

        $allocations = $payment->allocations;
        $months = [];

        foreach ($allocations as $allocation) {
            /** @var PaymentAllocation $allocation */
            $month = $allocation->due->month;
            $months[$month->toDateString()] = $month;
        }

        ksort($months);
        $allocated = Money::sum($allocations->map(fn (PaymentAllocation $allocation): Money => $allocation->amount_poisha)->all());
        $advance = $payment->amount_poisha->minus($allocated);
        $text = $months === [] ? '' : self::ranges(array_values($months));

        if ($advance->isPositive()) {
            $part = __('payments.for_months.advance', ['amount' => Display::moneyShort($advance)]);
            $text = $text === '' ? $part : $text.' + '.$part;
        }

        return $text === '' ? '—' : $text;
    }

    /**
     * What approving the payment now will settle, against the dues open at this moment (the same
     * order the approval uses), for the approval dialog.
     */
    public static function onApproval(Payment $payment): string
    {
        $previewer = app(PaymentPreviewer::class);
        ['allocations' => $allocations, 'remainder' => $remainder] = app(AllocationEngine::class)->allocate($payment->amount_poisha, $previewer->openDues($payment->member));
        $months = [];

        foreach ($allocations as ['due' => $due]) {
            $months[$due->month->toDateString()] = $due->month;
        }

        ksort($months);
        $parts = [];

        if ($months !== []) {
            $parts[] = self::ranges(array_values($months)).' ('.Display::moneyShort($payment->amount_poisha->minus($remainder)).')';
        }

        if ($remainder->isPositive()) {
            $parts[] = __('payments.for_months.advance', ['amount' => Display::moneyShort($remainder)]);
        }

        return __('payments.for_months.on_approval', ['what' => implode(' + ', $parts)]);
    }

    /**
     * Consecutive months as one range: [Jul, Aug, Sep, Nov] → "Jul – Sep 2026, Nov 2026".
     *
     * @param  list<YearMonth>  $months  sorted, distinct
     */
    public static function ranges(array $months): string
    {
        $parts = [];
        $start = $previous = null;

        foreach ([...$months, null] as $month) {
            if ($start !== null && $previous !== null && ($month === null || ! $month->equals($previous->next()))) {
                $parts[] = $start->equals($previous)
                    ? Display::yearMonth($start)
                    : Display::monthShort($start).($start->year === $previous->year ? '' : ' '.Display::digits($start->year)).' – '.Display::monthShort($previous).' '.Display::digits($previous->year);
                $start = null;
            }

            if ($month !== null) {
                $start ??= $month;
                $previous = $month;
            }
        }

        return implode(', ', $parts);
    }
}
