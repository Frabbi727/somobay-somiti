<?php

declare(strict_types=1);

namespace App\Domain\Contributions\LateEntry;

use App\Domain\Contributions\Services\MonthlyDueBuilder;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Services\RateResolver;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Late data entry (design 2026-11-25): a new member whose shares start in a month that already
 * has dues gets those months' regular dues, built exactly as the monthly run would have built
 * them, but due on the day they were entered so the delay costs them no late fee.
 */
final class LateEntryDues
{
    public function __construct(
        private readonly MonthlyDueBuilder $builder,
        private readonly RateResolver $rates,
        private readonly GeneratedMonths $generated,
    ) {}

    /**
     * The already-generated months from the share start on: empty when the shares start later.
     *
     * @return list<YearMonth>
     */
    public function months(YearMonth $shareStart): array
    {
        $latest = $this->generated->latest();
        $months = [];

        for ($month = $shareStart; $latest !== null && $month->isSameOrBefore($latest); $month = $month->next()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * Those months with what each comes to for this many shares, at each month's rate.
     *
     * @return list<array{month: YearMonth, deposit: Money, serviceCharge: Money}>
     */
    public function bill(int $shares, YearMonth $shareStart): array
    {
        return array_map(function (YearMonth $month) use ($shares): array {
            $plan = $this->rates->for($month);

            return [
                'month' => $month,
                'deposit' => $plan->share_unit_poisha->multipliedByInt($shares),
                'serviceCharge' => $plan->service_charge_per_share_poisha->multipliedByInt($shares),
            ];
        }, $this->months($shareStart));
    }

    /**
     * Creates the member's dues for those months inside the caller's transaction; returns how many.
     */
    public function write(User $actor, Member $member, YearMonth $shareStart, CarbonImmutable $enteredOn): int
    {
        $months = $this->months($shareStart);
        $created = 0;
        $amount = Money::zero();

        foreach ($months as $month) {
            foreach ($this->builder->rows($month, $this->rates->for($month), [$member->id]) as $row) {
                $row['due_date'] = $enteredOn->toDateString();

                if (DB::table('dues')->insertOrIgnore($row) === 1) {
                    $created++;
                    $amount = $amount->plus(Money::ofPoisha((int) $row['amount_poisha']));
                }
            }
        }

        if ($created > 0) {
            activity('members')
                ->causedBy($actor)
                ->performedOn($member)
                ->withProperties([
                    'months' => array_map(fn (YearMonth $month): string => (string) $month, $months),
                    'amount_poisha' => $amount->poisha,
                ])
                ->log('late_entry_dues_created');
        }

        return $created;
    }
}
