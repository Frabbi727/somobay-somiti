<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Contributions\Services\RegistrationFees;
use App\Domain\Members\Enums\ShareChangeType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Members\Models\ShareTransaction;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;

/**
 * Applies share changes inside the caller's transaction, with the member row already locked.
 *
 * BR-1: changes take effect from a stated month, never in a month that already has dues, and
 * never before the member's latest change (history is only ever extended).
 */
final class ShareChanger
{
    public function __construct(
        private readonly ShareRegister $register,
        private readonly RegistrationFees $fees,
        private readonly RateResolver $rates,
        private readonly GeneratedMonths $generated,
    ) {}

    public function increase(User $actor, Member $member, int $shares, YearMonth $from, ?string $reason = null): ShareLot
    {
        $this->assertCount($shares);
        $this->assertMonthAllowed($member, $from);
        $plan = $this->planFor($from);

        $lot = ShareLot::query()->create([
            'member_id' => $member->id,
            'shares' => $shares,
            'effective_from' => $from,
            'created_by' => $actor->id,
        ]);

        $this->fees->chargeNewLot($lot, $plan);
        $this->record($actor, $member, ShareChangeType::Increase, $shares, $from, $reason);

        return $lot;
    }

    public function decrease(User $actor, Member $member, int $shares, YearMonth $from, ?string $reason = null): void
    {
        $this->assertCount($shares);
        $this->assertMonthAllowed($member, $from);

        $current = $this->register->sharesIn($member, $from);

        if ($shares >= $current) {
            throw DomainRuleViolation::because('members.errors.too_few_shares_left', ['current' => $current]);
        }

        $remaining = $shares;

        foreach ($this->register->activeLotsIn($member, $from) as $lot) {
            if ($remaining === 0) {
                break;
            }

            if ($lot->effective_from->equals($from)) {
                throw DomainRuleViolation::because('members.errors.same_month_change', ['month' => (string) $from]);
            }

            $lot->ended_from = $from;
            $lot->save();

            if ($lot->shares > $remaining) {
                ShareLot::query()->create([
                    'member_id' => $member->id,
                    'shares' => $lot->shares - $remaining,
                    'effective_from' => $from,
                    'continues_lot_id' => $lot->id,
                    'created_by' => $actor->id,
                ]);
                $remaining = 0;
            } else {
                $remaining -= $lot->shares;
            }
        }

        $this->record($actor, $member, ShareChangeType::Decrease, $shares, $from, $reason);
    }

    /**
     * The month a new share change should start from by default: this month, or the month after
     * the last month that already has dues.
     */
    public function firstOpenMonth(): YearMonth
    {
        $generated = $this->generated->latest();

        return $generated === null ? YearMonth::current() : YearMonth::current()->max($generated->next());
    }

    /**
     * The last month that already has dues; no share change may start in or before it.
     */
    public function latestGeneratedMonth(): ?YearMonth
    {
        return $this->generated->latest();
    }

    public function planFor(YearMonth $month): RatePlan
    {
        try {
            return $this->rates->for($month);
        } catch (NoRatePlanForMonth) {
            throw DomainRuleViolation::because('members.errors.no_rate_plan', ['month' => (string) $month]);
        }
    }

    private function assertCount(int $shares): void
    {
        if ($shares < 1) {
            throw DomainRuleViolation::because('members.errors.shares_positive');
        }
    }

    private function assertMonthAllowed(Member $member, YearMonth $from): void
    {
        $generated = $this->generated->latest();

        if ($generated !== null && $from->isSameOrBefore($generated)) {
            throw DomainRuleViolation::because('members.errors.month_generated', [
                'month' => (string) $from,
                'latest' => (string) $generated,
            ]);
        }

        $last = $this->register->lastChangeMonth($member);

        if ($last !== null && $from->isBefore($last)) {
            throw DomainRuleViolation::because('members.errors.before_last_change', [
                'month' => (string) $from,
                'last' => (string) $last,
            ]);
        }
    }

    private function record(User $actor, Member $member, ShareChangeType $type, int $shares, YearMonth $from, ?string $reason): void
    {
        $this->register->rebuild($member);

        ShareTransaction::query()->create([
            'member_id' => $member->id,
            'type' => $type,
            'shares' => $shares,
            'shares_after' => $this->register->sharesIn($member, $from),
            'effective_from' => $from,
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
    }
}
