<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Services\RateResolver;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Support\Collection;

/**
 * W4: what a payment would do if approved now: which dues it settles, how much becomes advance,
 * and roughly which month that advance reaches at current rates. Read-only.
 */
final class PaymentPreviewer
{
    public function __construct(
        private readonly AllocationEngine $engine,
        private readonly RateResolver $rates,
    ) {}

    /**
     * @return Collection<int, Due>
     */
    public function openDues(Member $member): Collection
    {
        return $this->engine->order(Due::query()
            ->where('member_id', $member->id)
            ->where('status', DueStatus::Open)
            ->where('outstanding_poisha', '>', 0)
            ->get());
    }

    /**
     * What this amount is expected to settle. The member's other pending payments are approved
     * first in the usual case, so they are counted first: this payment's share is the allocation of
     * (pending + amount) minus the allocation of pending alone (allocation is greedy in a fixed
     * order, so the difference is exact). Final on approval, against the dues open then.
     *
     * @return array{allocations: list<array{due: Due, amount: Money}>, to_dues: Money, remainder: Money, covers_through: ?YearMonth, pending_count: int, pending_total: Money}
     */
    public function preview(Member $member, Money $amount): array
    {
        $dues = $this->openDues($member);
        $pending = Payment::query()->where('member_id', $member->id)->where('status', PaymentStatus::Pending)->get();
        $pendingTotal = Money::sum($pending->map(fn (Payment $payment): Money => $payment->amount_poisha)->all());

        $before = $this->engine->allocate($pendingTotal, $dues);
        $after = $this->engine->allocate($pendingTotal->plus($amount), $dues);

        $already = [];

        foreach ($before['allocations'] as ['due' => $due, 'amount' => $applied]) {
            $already[$due->id] = $applied;
        }

        $allocations = [];

        foreach ($after['allocations'] as ['due' => $due, 'amount' => $applied]) {
            $share = $applied->minus($already[$due->id] ?? Money::zero());

            if ($share->isPositive()) {
                $allocations[] = ['due' => $due, 'amount' => $share];
            }
        }

        $remainder = $after['remainder']->minus($before['remainder']);

        return [
            'allocations' => $allocations,
            'to_dues' => $amount->minus($remainder),
            'remainder' => $remainder,
            'covers_through' => $remainder->isPositive() ? $this->coversThrough($member, $remainder) : null,
            'pending_count' => $pending->count(),
            'pending_total' => $pendingTotal,
        ];
    }

    /**
     * "Pay this month": because dues settle oldest first (BR-13), a member can only clear a month
     * by also clearing every month before it. For each open month, the amount that does exactly that.
     *
     * @param  Collection<int, Due>  $openDues  as returned by openDues()
     * @return list<array{month: YearMonth, amount: Money}>
     */
    public function clearThrough(Collection $openDues): array
    {
        $rows = [];
        $running = Money::zero();

        foreach ($openDues->groupBy(fn (Due $due): string => $due->month->toDateString()) as $month => $dues) {
            $running = $running->plus(Money::sum($dues->map(fn (Due $due): Money => $due->outstanding_poisha)));
            $rows[] = ['month' => YearMonth::parse((string) $month), 'amount' => $running];
        }

        return $rows;
    }

    private function coversThrough(Member $member, Money $advance): ?YearMonth
    {
        $last = Due::query()->where('member_id', $member->id)->where('type', DueType::Deposit)->max('month');
        $from = $last === null ? YearMonth::current()->previous() : YearMonth::parse(substr((string) $last, 0, 10));
        $next = $from->next();
        $plan = $this->rates->find($next);
        $shares = $member->sharesIn($next);

        if ($plan === null || $shares === 0) {
            return null;
        }

        $monthly = $plan->share_unit_poisha->plus($plan->service_charge_per_share_poisha)->multipliedByInt($shares);
        $months = $monthly->isPositive() ? intdiv($advance->poisha, $monthly->poisha) : 0;

        return $months === 0 ? null : $from->addMonths($months);
    }
}
