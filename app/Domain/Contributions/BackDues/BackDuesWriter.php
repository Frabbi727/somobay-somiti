<?php

declare(strict_types=1);

namespace App\Domain\Contributions\BackDues;

use App\Domain\Contributions\Enums\DueOrigin;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Settings\Models\RatePlan;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

/**
 * Writes a BackDuesPlan as dues, inside CreateMember's transaction. Every back-due is due on the
 * joining date (payable now), carries its own month's rate snapshot and origin "back_dues", so the
 * late-fee run never fines it. The joining lot is not active in those months, so the monthly run
 * can never bill them a second time.
 */
final class BackDuesWriter
{
    public function write(User $actor, Member $member, ShareLot $lot, BackDuesPlan $plan, CarbonImmutable $joinedOn): void
    {
        if (! $plan->hasBackMonths()) {
            return;
        }

        $dueDate = $joinedOn->toDateString();

        foreach ($plan->months as $row) {
            $this->due($member, $lot, $row['month'], DueType::Deposit, $row['plan'], $row['deposit'], $dueDate);
            $this->due($member, $lot, $row['month'], DueType::ServiceCharge, $row['plan'], $row['serviceCharge'], $dueDate);
        }

        if ($plan->premium->isPositive() && $plan->premiumPlan !== null) {
            $this->due($member, $lot, $plan->shareStart, DueType::EntryPremium, $plan->premiumPlan, $plan->premium, $dueDate);
        }

        activity('members')
            ->causedBy($actor)
            ->performedOn($member)
            ->withProperties($plan->toLog())
            ->log('back_dues_created');
    }

    private function due(Member $member, ShareLot $lot, YearMonth $month, DueType $type, RatePlan $plan, Money $amount, string $dueDate): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        Due::query()->create([
            'member_id' => $member->id,
            'month' => $month,
            'type' => $type,
            'share_lot_id' => $lot->id,
            'adjustment_seq' => 0,
            'rate_plan_id' => $plan->id,
            'snapshot' => $plan->snapshot(),
            'amount_poisha' => $amount,
            'paid_poisha' => Money::zero(),
            'due_date' => $dueDate,
            'status' => DueStatus::Open,
            'origin' => DueOrigin::BackDues,
            'note' => $type === DueType::EntryPremium ? 'Entry premium' : 'Back-dues from joining',
        ]);
    }
}
