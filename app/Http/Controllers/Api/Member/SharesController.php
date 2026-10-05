<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Models\ShareTransaction;
use App\Domain\Settings\Services\RateResolver;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's shares this month, every share change, and this month's rates from the approved
 * rate plan (null when no plan covers the month).
 */
final class SharesController
{
    use ResolvesMember;

    public function overview(Request $request, RateResolver $rates): JsonResponse
    {
        $member = self::member($request);
        $month = YearMonth::current();
        $plan = $rates->find($month);

        return ApiResponse::ok([
            'current_shares' => $member->sharesIn($month),
            'history' => ShareTransaction::query()->where('member_id', $member->id)->orderByDesc('effective_from')->orderByDesc('id')->get()
                ->map(fn (ShareTransaction $change): array => [
                    'type' => ApiValue::enum($change->type),
                    'shares' => $change->shares,
                    'shares_after' => $change->shares_after,
                    'effective_from' => ApiValue::month($change->effective_from),
                    'reason' => $change->reason,
                ])->all(),
            'rates' => $plan === null ? null : [
                'effective_from' => ApiValue::month($plan->effective_from),
                'share_unit' => ApiValue::money($plan->share_unit_poisha),
                'service_charge_per_share' => ApiValue::money($plan->service_charge_per_share_poisha),
                'registration_fee_per_share' => ApiValue::money($plan->registration_fee_per_share_poisha),
                'due_day' => $plan->due_day,
                'grace_days' => $plan->grace_days,
                'late_fee' => [
                    'mode' => ApiValue::enum($plan->late_fee_mode),
                    'fixed' => ApiValue::money($plan->late_fee_fixed_poisha),
                    'percent' => $plan->late_fee_bps === null ? null : Bps::of($plan->late_fee_bps)->toPercentString(),
                    'cap' => ApiValue::money($plan->late_fee_cap_poisha),
                    'frequency' => ApiValue::enum($plan->late_fee_frequency),
                ],
            ],
        ]);
    }
}
