<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Contributions\Services\RegistrationFees;
use App\Domain\GoLive\Services\GoLiveMonth;
use App\Domain\Members\Enums\ShareChangeType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Members\Models\ShareTransaction;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Models\YearEnd;
use App\Models\User;
use App\Support\Time\YearMonth;

/**
 * Applies share changes inside the caller's transaction, with the member row already locked.
 *
 * BR-1: changes take effect from a stated month, never in a month that already has dues, and
 * never before the member's latest change (history is only ever extended). A new member's first
 * lot is the exception: it may start in a month that already has dues (late data entry), as long
 * as that month's year is still open; their missing dues for those months are created separately.
 */
final class ShareChanger
{
    public function __construct(
        private readonly ShareRegister $register,
        private readonly RegistrationFees $fees,
        private readonly RateResolver $rates,
        private readonly GeneratedMonths $generated,
        private readonly GoLiveMonth $goLive,
    ) {}

    public function increase(User $actor, Member $member, int $shares, YearMonth $from, ?string $reason = null): ShareLot
    {
        $this->assertCount($shares);
        $this->assertMonthAllowed($member, $from);

        return $this->addLot($actor, $member, $shares, $from, $reason);
    }

    /**
     * A new member's first lot. Unlike increase(), it may start in a month that already has dues.
     */
    public function join(User $actor, Member $member, int $shares, YearMonth $from): ShareLot
    {
        $this->assertCount($shares);
        $this->assertJoinMonthAllowed($from);

        return $this->addLot($actor, $member, $shares, $from, 'Joined');
    }

    /**
     * Where a new member's shares may start: not before go-live, not in a closed fiscal year or one
     * whose year-end (dividend) is already prepared, and only where a rate plan exists.
     */
    public function assertJoinMonthAllowed(YearMonth $from): void
    {
        $goLive = $this->goLive->get();

        if ($goLive !== null && $from->isBefore($goLive)) {
            throw DomainRuleViolation::because('members.errors.join_before_go_live', ['month' => (string) $goLive]);
        }

        $fiscalYear = FiscalYear::query()->where('start_year', FiscalCalendar::startYearFor($from))->first();

        if ($fiscalYear !== null && $fiscalYear->status === FiscalYearStatus::Closed) {
            throw DomainRuleViolation::because('members.errors.join_year_closed', ['year' => $fiscalYear->code]);
        }

        if ($fiscalYear !== null && YearEnd::query()->where('fiscal_year_id', $fiscalYear->id)->exists()) {
            throw DomainRuleViolation::because('members.errors.join_year_end', ['year' => $fiscalYear->code]);
        }

        $this->planFor($from);
    }

    private function addLot(User $actor, Member $member, int $shares, YearMonth $from, ?string $reason): ShareLot
    {
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

    /**
     * A lot brought in by the go-live import: effective from the member's original month, with no
     * rate-plan lookup and no registration fee (paid before the app existed).
     */
    public function openingLot(User $actor, Member $member, int $shares, YearMonth $from): ShareLot
    {
        $this->assertCount($shares);

        $lot = ShareLot::query()->create([
            'member_id' => $member->id,
            'shares' => $shares,
            'effective_from' => $from,
            'created_by' => $actor->id,
        ]);

        $this->record($actor, $member, ShareChangeType::Increase, $shares, $from, 'Opening balance');

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
     * Ends every share from the month after the exit month, when an exit is approved: the member
     * holds no shares from then on, so no dues are built and no dividend is shared for them.
     * Returns how many shares ended.
     */
    public function endAllForExit(User $actor, Member $member, YearMonth $exitMonth, string $reason): int
    {
        $end = $exitMonth->next();

        $lots = $this->register->activeLotsIn($member, $exitMonth);

        // A share change already planned for after the exit month would keep shares alive.
        $changeAfterExit = ShareLot::query()
            ->where('member_id', $member->id)
            ->where('effective_from', '>=', $end->toDateString())
            ->exists()
            || $lots->contains(fn (ShareLot $lot): bool => $lot->ended_from !== null && $lot->ended_from->isAfter($end));

        if ($changeAfterExit) {
            throw DomainRuleViolation::because('exits.errors.shares_after_exit', ['month' => (string) $exitMonth]);
        }

        $ended = 0;

        foreach ($lots as $lot) {
            if ($lot->ended_from !== null) {
                continue;
            }

            $lot->ended_from = $end;
            $lot->save();
            $ended += $lot->shares;
        }

        if ($ended > 0) {
            $this->record($actor, $member, ShareChangeType::Exit, $ended, $end, $reason);
        }

        return $ended;
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
