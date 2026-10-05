<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Portal\MemberSummary;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Resources\Api\PaymentSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The portal dashboard (MemberStatsWidget + RecentPaymentsWidget) for the app's home screen.
 */
final class DashboardController
{
    use ResolvesMember;

    public function summary(Request $request, MemberSummary $summaries): JsonResponse
    {
        $member = self::member($request);
        $summary = $summaries->for($member);

        return ApiResponse::ok([
            'member' => ['member_no' => $member->member_no, 'name' => self::memberName($member), 'status' => ApiValue::enum($member->status)],
            'savings' => ApiValue::money($summary['savings']),
            'advance' => ApiValue::money($summary['advance']),
            'outstanding' => ApiValue::money($summary['outstanding']),
            'paid_through' => ApiValue::month($summary['paid_through']),
            'advance_months_estimate' => $summary['estimate'],
            'shares' => $summary['shares'],
            'pay_now_visible' => $summary['outstanding']->isPositive(),
            'recent_payments' => Payment::query()->where('member_id', $member->id)->latest('received_on')->latest('id')->limit(5)->get()
                ->map(fn (Payment $payment): array => PaymentSummaryResource::make($payment))->all(),
        ]);
    }
}
