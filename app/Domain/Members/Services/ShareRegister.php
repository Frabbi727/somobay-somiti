<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberShareSnapshot;
use App\Domain\Members\Models\ShareLot;
use App\Support\Time\YearMonth;
use Illuminate\Support\Collection;

/**
 * Reads and maintains the share timeline (member_share_snapshots) from share lots.
 */
final class ShareRegister
{
    public function sharesIn(Member $member, YearMonth $month): int
    {
        return $member->sharesIn($month);
    }

    /**
     * Lots counting in a month, oldest first (the order a decrease consumes them).
     *
     * @return Collection<int, ShareLot>
     */
    public function activeLotsIn(Member $member, YearMonth $month): Collection
    {
        return ShareLot::query()
            ->where('member_id', $member->id)
            ->where('effective_from', '<=', $month->toDateString())
            ->where(fn ($query) => $query->whereNull('ended_from')->orWhere('ended_from', '>', $month->toDateString()))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }

    /**
     * The latest month in which this member's shares changed, if any.
     */
    public function lastChangeMonth(Member $member): ?YearMonth
    {
        $starts = ShareLot::query()->where('member_id', $member->id)->max('effective_from');
        $ends = ShareLot::query()->where('member_id', $member->id)->max('ended_from');
        $latest = max((string) $starts, (string) $ends);

        return $latest === '' ? null : YearMonth::parse(substr($latest, 0, 10));
    }

    /**
     * Rewrites the member's timeline: one row per month in which the share count changes.
     */
    public function rebuild(Member $member): void
    {
        $lots = ShareLot::query()->where('member_id', $member->id)->get();

        $months = [];

        foreach ($lots as $lot) {
            $months[$lot->effective_from->toDateString()] = $lot->effective_from;

            if ($lot->ended_from !== null) {
                $months[$lot->ended_from->toDateString()] = $lot->ended_from;
            }
        }

        ksort($months);

        MemberShareSnapshot::query()->where('member_id', $member->id)->delete();

        $previous = null;

        foreach ($months as $month) {
            $shares = $lots->filter(fn (ShareLot $lot): bool => $lot->isActiveIn($month))->sum('shares');

            if ($shares === $previous) {
                continue;
            }

            MemberShareSnapshot::query()->create([
                'member_id' => $member->id,
                'effective_from' => $month,
                'shares' => $shares,
            ]);

            $previous = $shares;
        }
    }
}
