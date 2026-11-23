<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Portal\MemberPosition;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Resources\Api\PaymentSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The portal dashboard (MemberPositionWidget + RecentPaymentsWidget) for the app's home screen.
 */
final class DashboardController
{
    use ResolvesMember;

    public function summary(Request $request, MemberPosition $positions): JsonResponse
    {
        $member = self::member($request);
        $position = $positions->for($member);

        return ApiResponse::ok([
            'member' => ['member_no' => $member->member_no, 'name' => self::memberName($member), 'status' => ApiValue::enum($member->status)],
            'savings' => ApiValue::money($position->savings),
            'advance' => ApiValue::money($position->advance),
            'outstanding' => ApiValue::money($position->outstanding),
            'owe_now' => ApiValue::money($position->oweNow),
            'coming_up' => ApiValue::money($position->comingUp),
            'other_owed' => ApiValue::money($position->otherOwed),
            'paid_total' => ApiValue::money($position->paidTotal),
            'pending_total' => ApiValue::money($position->pendingTotal),
            'pending_count' => $position->pendingCount,
            'paid_through' => ApiValue::month($position->paidThrough),
            'advance_months_estimate' => $position->advanceCoversMonths,
            'shares' => $position->shares,
            'monthly_amount' => $position->monthlyAmount === null ? null : ApiValue::money($position->monthlyAmount),
            'months' => array_map(fn (array $row): array => [
                'month' => ApiValue::month($row['month']),
                'state' => $row['state']->value,
                'owed' => ApiValue::money($row['owed']),
            ], $position->months),
            // Kept for app versions released before 2026-11 (remove after the app update).
            'monthly_deposit' => $position->monthlyDeposit === null ? null : ApiValue::money($position->monthlyDeposit),
            'billed' => ApiValue::money($position->billed),
            'paid' => ApiValue::money($position->paidOnDues),
            'waived' => ApiValue::money($position->waived),
            'due_this_month' => ApiValue::money($position->dueThisMonth),
            'due_earlier' => ApiValue::money($position->dueEarlier),
            'pay_now_visible' => $position->outstanding->isPositive(),
            'recent_payments' => Payment::query()->where('member_id', $member->id)->latest('received_on')->latest('id')->limit(5)->get()
                ->map(fn (Payment $payment): array => PaymentSummaryResource::make($payment))->all(),
        ]);
    }
}
