<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Accounting\AccountCode;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Services\RateResolver;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of a member's position (SOMITI design 2026-11-22 §3).
 *
 * "Paid through" looks only at monthly charges (deposit and service charge): it is the latest
 * month M such that every monthly due for M and every earlier month is settled, waived or
 * cancelled. Late fees, registration fees and the entry premium never move it; they are reported
 * as "other amounts owed".
 */
final class MemberPosition
{
    public const int STRIP_MONTHS = 12;

    public function __construct(
        private readonly AdvanceLedger $advances,
        private readonly RateResolver $rates,
        private readonly GeneratedMonths $generated,
    ) {}

    public function for(Member $member, ?CarbonImmutable $today = null): MemberPositionData
    {
        $today ??= CarbonImmutable::now(YearMonth::TIMEZONE);
        $current = YearMonth::fromDate($today);
        $todayString = $today->toDateString();

        /** @var Collection<int, Due> $dues */
        $dues = Due::query()->where('member_id', $member->id)->orderBy('month')->orderBy('id')->get();
        $open = $dues->filter(fn (Due $due): bool => $due->status === DueStatus::Open && $due->outstanding_poisha->isPositive());
        $live = $dues->filter(fn (Due $due): bool => $due->status !== DueStatus::Cancelled);

        $sum = fn (Collection $items, string $column): Money => Money::sum($items->map(fn (Due $due): Money => $due->{$column})->values()->all());

        $oweNow = $sum($open->filter(fn (Due $due): bool => $due->due_date->toDateString() <= $todayString), 'outstanding_poisha');
        $outstanding = $sum($open, 'outstanding_poisha');

        $payments = DB::table('payments')
            ->where('member_id', $member->id)
            ->selectRaw('COALESCE(SUM(amount_poisha) FILTER (WHERE status = ?), 0)::bigint AS approved', [PaymentStatus::Approved->value])
            ->selectRaw('COALESCE(SUM(amount_poisha) FILTER (WHERE status = ?), 0)::bigint AS pending', [PaymentStatus::Pending->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS pending_count', [PaymentStatus::Pending->value])
            ->first();

        $shares = $member->sharesIn($current);
        [$monthlyAmount, $monthlyDeposit] = $this->monthlyAmounts($current, $shares);
        $advance = $this->advances->balance($member->id);

        return new MemberPositionData(
            oweNow: $oweNow,
            comingUp: $outstanding->minus($oweNow),
            outstanding: $outstanding,
            otherOwed: $sum($open->filter(fn (Due $due): bool => ! $due->type->isMonthly()), 'outstanding_poisha'),
            paidTotal: Money::ofPoisha((int) ($payments->approved ?? 0)),
            pendingTotal: Money::ofPoisha((int) ($payments->pending ?? 0)),
            pendingCount: (int) ($payments->pending_count ?? 0),
            advance: $advance,
            savings: $this->savings($member),
            paidThrough: $this->paidThrough($dues),
            monthlyAmount: $monthlyAmount,
            advanceCoversMonths: $monthlyAmount !== null && $monthlyAmount->isPositive() && $advance->isPositive()
                ? intdiv($advance->poisha, $monthlyAmount->poisha)
                : 0,
            shares: $shares,
            billed: $sum($live, 'amount_poisha'),
            paidOnDues: $sum($live, 'paid_poisha'),
            waived: $sum($dues->filter(fn (Due $due): bool => $due->status === DueStatus::Waived), 'outstanding_poisha'),
            dueThisMonth: $sum($open->filter(fn (Due $due): bool => $due->month->equals($current)), 'outstanding_poisha'),
            dueEarlier: $sum($open->filter(fn (Due $due): bool => $due->month->isBefore($current)), 'outstanding_poisha'),
            monthlyDeposit: $monthlyDeposit,
            months: $this->months($member, $dues, $current, $todayString),
        );
    }

    /**
     * Paid through only (for lists with many members).
     */
    public function paidThroughOf(Member $member): ?YearMonth
    {
        return $this->paidThrough(Due::query()
            ->where('member_id', $member->id)
            ->whereIn('type', [DueType::Deposit, DueType::ServiceCharge])
            ->get());
    }

    /**
     * Estimate of whole months the advance covers at the current monthly amount.
     */
    public function advanceCoversMonthsOf(Member $member): int
    {
        $current = YearMonth::current();
        [$monthly] = $this->monthlyAmounts($current, $member->sharesIn($current));
        $advance = $this->advances->balance($member->id);

        return $monthly !== null && $monthly->isPositive() && $advance->isPositive() ? intdiv($advance->poisha, $monthly->poisha) : 0;
    }

    /**
     * @param  Collection<int, Due>  $dues
     */
    public function paidThrough(Collection $dues): ?YearMonth
    {
        $paidThrough = null;

        $byMonth = $dues
            ->filter(fn (Due $due): bool => $due->type->isMonthly())
            ->groupBy(fn (Due $due): string => $due->month->toDateString())
            ->sortKeys();

        foreach ($byMonth as $month => $monthDues) {
            $done = $monthDues->every(fn (Due $due): bool => $due->status !== DueStatus::Open || ! $due->outstanding_poisha->isPositive());

            if (! $done) {
                break;
            }

            $paidThrough = YearMonth::parse((string) $month);
        }

        return $paidThrough;
    }

    /**
     * @return array{0: Money|null, 1: Money|null} [deposit + service charge, deposit only]
     */
    private function monthlyAmounts(YearMonth $month, int $shares): array
    {
        try {
            $plan = $this->rates->for($month);
        } catch (NoRatePlanForMonth) {
            return [null, null];
        }

        $deposit = $plan->share_unit_poisha->multipliedByInt($shares);

        return [$deposit->plus($plan->service_charge_per_share_poisha->multipliedByInt($shares)), $deposit];
    }

    private function savings(Member $member): Money
    {
        return Money::ofPoisha((int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', AccountCode::MEMBER_SAVINGS)
            ->where('l.member_id', $member->id)
            ->sum(DB::raw('l.credit_poisha - l.debit_poisha')));
    }

    /**
     * @param  Collection<int, Due>  $dues
     * @return list<array{month: YearMonth, state: MonthState, owed: Money}>
     */
    private function months(Member $member, Collection $dues, YearMonth $current, string $today): array
    {
        $monthly = $dues
            ->filter(fn (Due $due): bool => $due->type->isMonthly())
            ->groupBy(fn (Due $due): string => $due->month->toDateString());
        $latest = $this->generated->latest();
        $start = $member->dues_start_month;
        $rows = [];

        for ($month = $current->addMonths(-(self::STRIP_MONTHS - 1)); ! $month->isAfter($current); $month = $month->next()) {
            /** @var Collection<int, Due> $monthDues */
            $monthDues = $monthly->get($month->toDateString(), collect());
            $owed = Money::sum($monthDues
                ->filter(fn (Due $due): bool => $due->status === DueStatus::Open)
                ->map(fn (Due $due): Money => $due->outstanding_poisha)->values()->all());

            $rows[] = [
                'month' => $month,
                'state' => $this->state($month, $monthDues, $start, $latest, $today),
                'owed' => $owed,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Due>  $monthDues
     */
    private function state(YearMonth $month, Collection $monthDues, ?YearMonth $start, ?YearMonth $latest, string $today): MonthState
    {
        if ($monthDues->isEmpty()) {
            return $start !== null && $month->isBefore($start) ? MonthState::BeforeStart : MonthState::NotBilled;
        }

        $live = $monthDues->filter(fn (Due $due): bool => $due->status !== DueStatus::Cancelled);

        if ($live->isEmpty()) {
            return MonthState::Cancelled;
        }

        $open = $live->filter(fn (Due $due): bool => $due->status === DueStatus::Open && $due->outstanding_poisha->isPositive());

        if ($open->isEmpty()) {
            return $live->contains(fn (Due $due): bool => $due->status === DueStatus::Waived) ? MonthState::Waived : MonthState::Paid;
        }

        if ($live->contains(fn (Due $due): bool => $due->paid_poisha->isPositive())) {
            return MonthState::PartlyPaid;
        }

        return $open->contains(fn (Due $due): bool => $due->due_date->toDateString() < $today) ? MonthState::Unpaid : MonthState::NotDueYet;
    }
}
