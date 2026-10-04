<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Services;

use App\Domain\Accounting\Services\FiscalCalendar;
use Illuminate\Support\Facades\DB;

/**
 * Share-months (BR-21): for every month of the fiscal year, the shares each member held that
 * month (from the share timeline), summed. A member with 2 shares all year has 24.
 */
final class ShareMonths
{
    /**
     * @return array<int, int> member id => share-months, members with none left out, ordered by id
     */
    public function forYear(int $startYear): array
    {
        $months = FiscalCalendar::months($startYear);
        $last = $months[count($months) - 1];

        $snapshots = DB::table('member_share_snapshots')
            ->where('effective_from', '<=', $last->toDateString())
            ->orderBy('member_id')
            ->orderBy('effective_from')
            ->get(['member_id', 'effective_from', 'shares'])
            ->groupBy('member_id');

        $result = [];

        foreach ($snapshots as $memberId => $timeline) {
            $total = 0;

            foreach ($months as $month) {
                $shares = 0;

                foreach ($timeline as $snapshot) {
                    if ($snapshot->effective_from <= $month->toDateString()) {
                        $shares = (int) $snapshot->shares;
                    }
                }

                $total += $shares;
            }

            if ($total > 0) {
                $result[(int) $memberId] = $total;
            }
        }

        ksort($result);

        return $result;
    }
}
