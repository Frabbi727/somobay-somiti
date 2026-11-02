<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
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
     * @return array{allocations: list<array{due: Due, amount: Money}>, to_dues: Money, remainder: Money, covers_through: ?YearMonth}
     */
    public function preview(Member $member, Money $amount): array
    {
        ['allocations' => $allocations, 'remainder' => $remainder] = $this->engine->allocate($amount, $this->openDues($member));

        return [
            'allocations' => $allocations,
            'to_dues' => $amount->minus($remainder),
            'remainder' => $remainder,
            'covers_through' => $remainder->isPositive() ? $this->coversThrough($member, $remainder) : null,
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
