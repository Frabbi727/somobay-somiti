<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Events\LateFeesApplied;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\LateFeeCalculator;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * W5 (daily 01:00): charges late fees on unpaid dues past their grace period.
 *
 * One late fee per member per month, attached to that month's deposit due (or its first due
 * when there is no deposit). "Once" charges a single fee; "monthly until paid" adds one per
 * further month while anything is still outstanding. Idempotent through the dues natural key
 * (member, month, late_fee, lot, adjustment_seq = charge number).
 */
final class ApplyLateFees
{
    public function __construct(private readonly LateFeeCalculator $calculator) {}

    /**
     * @return array{count: int, total: Money}
     */
    public function __invoke(?CarbonImmutable $today = null, ?User $actor = null): array
    {
        $today = ($today ?? CarbonImmutable::now(YearMonth::TIMEZONE))->startOfDay();
        $count = 0;
        $total = Money::zero();

        foreach ($this->candidateMembers($today) as $memberId) {
            DB::transaction(function () use ($memberId, $today, &$count, &$total): void {
                Member::query()->whereKey($memberId)->lockForUpdate()->first();

                $dues = Due::query()
                    ->where('member_id', $memberId)
                    ->whereIn('type', [DueType::Deposit, DueType::ServiceCharge, DueType::Registration, DueType::LateFee])
                    ->whereNotIn('status', [DueStatus::Cancelled])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($dues->where('type', '!=', DueType::LateFee)->groupBy(fn (Due $due): string => (string) $due->month) as $monthDues) {
                    foreach ($this->chargeMonth($monthDues, $dues, $today) as $fee) {
                        $count++;
                        $total = $total->plus($fee);
                    }
                }
            }, attempts: 3);
        }

        if ($count > 0) {
            activity('dues')
                ->causedBy($actor)
                ->event('late_fees_applied')
                ->withProperties(['date' => $today->toDateString(), 'count' => $count, 'amount_poisha' => $total->poisha])
                ->log('late_fees_applied');

            event(new LateFeesApplied($count, $total));
        }

        return ['count' => $count, 'total' => $total];
    }

    /**
     * @param  Collection<int, Due>  $monthDues  non-late-fee dues of one member and month
     * @param  Collection<int, Due>  $allDues
     * @return list<Money> the fees created
     */
    private function chargeMonth(Collection $monthDues, Collection $allDues, CarbonImmutable $today): array
    {
        $parent = $monthDues->firstWhere('type', DueType::Deposit) ?? $monthDues->first();

        if ($parent === null) {
            return [];
        }

        $snapshot = $parent->snapshot;
        $wanted = $this->calculator->chargesDue($snapshot, $parent->due_date, $today);

        $existing = $allDues->where('type', DueType::LateFee)->where('parent_due_id', $parent->id);
        $charged = Money::sum($existing->where('status', '!=', DueStatus::Waived)->map(fn (Due $due): Money => $due->amount_poisha));
        $created = [];

        for ($index = $existing->count(); $index < $wanted; $index++) {
            $base = $this->calculator->base($snapshot, $monthDues);

            if (! $base->isPositive()) {
                break;
            }

            $fee = $this->calculator->fee($snapshot, $base, $charged);

            if (! $fee->isPositive()) {
                break;
            }

            $inserted = DB::table('dues')->insertOrIgnore([
                'member_id' => $parent->member_id,
                'month' => $parent->month->toDateString(),
                'type' => DueType::LateFee->value,
                'share_lot_id' => $parent->share_lot_id,
                'adjustment_seq' => $index,
                'rate_plan_id' => $parent->rate_plan_id,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'amount_poisha' => $fee->poisha,
                'paid_poisha' => 0,
                'due_date' => $this->calculator->chargeDate($snapshot, $parent->due_date, $index)->toDateString(),
                'parent_due_id' => $parent->id,
                'status' => DueStatus::Open->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 1) {
                $charged = $charged->plus($fee);
                $created[] = $fee;
            }
        }

        return $created;
    }

    /**
     * Members with at least one unpaid deposit, service or registration due already past its due date.
     *
     * @return Collection<int, int>
     */
    private function candidateMembers(CarbonImmutable $today): Collection
    {
        return DB::table('dues as d')
            ->join('members as m', 'm.id', '=', 'd.member_id')
            ->where('m.status', '!=', MemberStatus::Exited->value)
            ->whereIn('d.type', [DueType::Deposit->value, DueType::ServiceCharge->value, DueType::Registration->value])
            ->where('d.status', DueStatus::Open->value)
            ->where('d.outstanding_poisha', '>', 0)
            ->where('d.due_date', '<', $today->toDateString())
            ->distinct()
            ->orderBy('d.member_id')
            ->pluck('d.member_id')
            ->map(fn (mixed $id): int => (int) $id);
    }
}
