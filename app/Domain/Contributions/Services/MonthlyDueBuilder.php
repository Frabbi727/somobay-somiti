<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Settings\Models\RatePlan;
use App\Support\Time\YearMonth;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the deposit and service-charge rows for a month (W2, BR-8/9): one row per active lot
 * of each active member, priced from the month's plan, with the plan snapshot copied in.
 */
final class MonthlyDueBuilder
{
    /**
     * Ids of members who get dues for the month, in a stable order for chunking.
     *
     * @return Collection<int, int>
     */
    public function memberIds(YearMonth $month): Collection
    {
        return $this->activeLots($month)
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->where('m.status', MemberStatus::Active->value)
            ->whereNull('m.deleted_at')
            ->distinct()
            ->orderBy('l.member_id')
            ->pluck('l.member_id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * @param  array<int, int>  $memberIds
     * @return list<array<string, mixed>> rows ready for insertOrIgnore
     */
    public function rows(YearMonth $month, RatePlan $plan, array $memberIds): array
    {
        $lots = $this->activeLots($month)
            ->whereIn('l.member_id', $memberIds)
            ->orderBy('l.member_id')
            ->orderBy('l.id')
            ->get(['l.id', 'l.member_id', 'l.shares']);

        $snapshot = json_encode($plan->snapshot(), JSON_THROW_ON_ERROR);
        $dueDate = $month->day($plan->due_day)->toDateString();
        $now = now();
        $rows = [];

        foreach ($lots as $lot) {
            foreach ([DueType::Deposit, DueType::ServiceCharge] as $type) {
                $perShare = $type === DueType::Deposit ? $plan->share_unit_poisha : $plan->service_charge_per_share_poisha;
                $amount = $perShare->multipliedByInt((int) $lot->shares);

                if (! $amount->isPositive()) {
                    continue;
                }

                $rows[] = [
                    'member_id' => (int) $lot->member_id,
                    'month' => $month->toDateString(),
                    'type' => $type->value,
                    'share_lot_id' => (int) $lot->id,
                    'adjustment_seq' => 0,
                    'rate_plan_id' => $plan->id,
                    'snapshot' => $snapshot,
                    'amount_poisha' => $amount->poisha,
                    'paid_poisha' => 0,
                    'due_date' => $dueDate,
                    'status' => DueStatus::Open->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return Builder
     */
    private function activeLots(YearMonth $month)
    {
        $date = $month->toDateString();

        return DB::table((new ShareLot)->getTable().' as l')
            ->where('l.effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('l.ended_from')->orWhere('l.ended_from', '>', $date));
    }
}
