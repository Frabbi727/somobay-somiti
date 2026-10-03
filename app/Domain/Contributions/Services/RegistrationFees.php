<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Settings\Enums\RegistrationFeePolicy;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateResolver;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * Registration fee dues (BR-4): charged once per share when the share takes effect, at the fee
 * of that month's plan. A later rate change never re-charges a share, except the optional
 * "difference" policy, which charges existing shares a one-time top-up when the fee rises.
 */
final class RegistrationFees
{
    public function __construct(private readonly RateResolver $rates) {}

    /**
     * Charges a newly acquired lot. Continuation lots (left over after a decrease) are never charged.
     */
    public function chargeNewLot(ShareLot $lot, RatePlan $plan): ?Due
    {
        if ($lot->continues_lot_id !== null) {
            return null;
        }

        return $this->charge($lot, $plan, $plan->registration_fee_per_share_poisha->multipliedByInt($lot->shares), 'Registration fee');
    }

    /**
     * Applies the "difference" policy for a just-approved plan: every lot that already counted
     * before the plan's month is charged (new fee − previous fee) × its shares.
     *
     * @return list<Due>
     */
    public function topUpForPlan(RatePlan $plan): array
    {
        if ($plan->registration_fee_on_rate_increase !== RegistrationFeePolicy::Difference) {
            return [];
        }

        $previous = $this->rates->find($plan->effective_from->previous());

        if ($previous === null) {
            return [];
        }

        $difference = $plan->registration_fee_per_share_poisha->minus($previous->registration_fee_per_share_poisha);

        if (! $difference->isPositive()) {
            return [];
        }

        $month = $plan->effective_from->toDateString();

        $lots = ShareLot::query()
            ->where('effective_from', '<', $month)
            ->where(fn ($query) => $query->whereNull('ended_from')->orWhere('ended_from', '>', $month))
            ->whereHas('member', fn ($query) => $query->where('status', MemberStatus::Active))
            ->orderBy('id')
            ->get();

        $dues = [];

        foreach ($lots as $lot) {
            $due = $this->charge($lot, $plan, $difference->multipliedByInt($lot->shares), 'Registration fee top-up', $plan->effective_from);

            if ($due !== null) {
                $dues[] = $due;
            }
        }

        return $dues;
    }

    private function charge(ShareLot $lot, RatePlan $plan, Money $amount, string $note, ?YearMonth $month = null): ?Due
    {
        if (! $amount->isPositive()) {
            return null;
        }

        $dueMonth = $month ?? $lot->effective_from;

        return Due::query()->create([
            'member_id' => $lot->member_id,
            'month' => $dueMonth,
            'type' => DueType::Registration,
            'share_lot_id' => $lot->id,
            'adjustment_seq' => 0,
            'rate_plan_id' => $plan->id,
            'snapshot' => $plan->snapshot(),
            'amount_poisha' => $amount,
            'paid_poisha' => Money::zero(),
            'due_date' => $dueMonth->day($plan->due_day)->toDateString(),
            'status' => DueStatus::Open,
            'note' => $note,
        ]);
    }
}
