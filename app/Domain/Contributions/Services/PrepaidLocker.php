<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\ShareRegister;
use App\Domain\Settings\Models\RatePlan;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * "lock_prepaid_months" (§5.4): when a payment leaves money over, create the following months'
 * deposit and service dues now, at the current plan, marked prepaid_locked, for as many whole
 * months as the money covers. Later rate changes never touch them; monthly generation skips them
 * because they already occupy the natural key.
 */
final class PrepaidLocker
{
    public const int MAX_MONTHS = 24;

    public function __construct(private readonly ShareRegister $register) {}

    /**
     * @return list<Due> the locked dues created, oldest first
     */
    public function lock(Member $member, RatePlan $plan, Money $available, YearMonth $after): array
    {
        $created = [];
        $month = $after->next();

        for ($i = 0; $i < self::MAX_MONTHS; $i++, $month = $month->next()) {
            $lots = $this->register->activeLotsIn($member, $month);
            $rows = [];

            foreach ($lots as $lot) {
                foreach ([[DueType::Deposit, $plan->share_unit_poisha], [DueType::ServiceCharge, $plan->service_charge_per_share_poisha]] as [$type, $perShare]) {
                    $amount = $perShare->multipliedByInt($lot->shares);

                    if ($amount->isPositive()) {
                        $rows[] = ['type' => $type, 'lot' => $lot->id, 'amount' => $amount];
                    }
                }
            }

            $monthTotal = Money::sum(array_column($rows, 'amount'));

            if ($rows === [] || $monthTotal->isGreaterThan($available)) {
                break;
            }

            $exists = Due::query()->where('member_id', $member->id)->where('month', $month->toDateString())
                ->whereIn('type', [DueType::Deposit, DueType::ServiceCharge])->exists();

            if ($exists) {
                break;
            }

            foreach ($rows as $row) {
                $created[] = Due::query()->create([
                    'member_id' => $member->id,
                    'month' => $month,
                    'type' => $row['type'],
                    'share_lot_id' => $row['lot'],
                    'adjustment_seq' => 0,
                    'rate_plan_id' => $plan->id,
                    'snapshot' => $plan->snapshot(),
                    'amount_poisha' => $row['amount'],
                    'paid_poisha' => Money::zero(),
                    'due_date' => $month->day($plan->due_day)->toDateString(),
                    'prepaid_locked' => true,
                    'status' => DueStatus::Open,
                    'note' => 'Prepaid at locked rate',
                ])->refresh(); // load the database-computed outstanding_poisha
            }

            $available = $available->minus($monthTotal);
        }

        return $created;
    }
}
