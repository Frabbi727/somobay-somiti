<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Enums\TimelineState;
use App\Domain\Reporting\Data\PendingItem;
use App\Domain\Settings\Enums\ApprovalDecision;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Support\Display;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The history of a record as a list of steps — entered, approved, rejected, reversed… — built only
 * from what the record itself stores, so it never shows a step that did not happen. While it waits,
 * the last step names the people who can act on it now (or warns that nobody can).
 *
 * Expenses, transfers and investments keep no separate "rejected at"/"cancelled at" time; those
 * states are final, so the record's last update is that moment.
 */
final class RecordTimeline
{
    /** @var array<int, string> */
    private array $names = [];

    public function __construct(private readonly ApprovalQueue $queue) {}

    /**
     * @return list<TimelineStep>
     */
    public function steps(Model $record): array
    {
        return match (true) {
            $record instanceof Payment => $this->checked($record, 'payment', $record->status->value, $record->recorded_by, $record->created_at, [
                'approved_at' => $record->getAttribute('approved_at'), 'rejected_at' => $record->getAttribute('rejected_at'), 'cancelled_at' => $record->getAttribute('cancelled_at'),
            ], $record->rejection_reason, $record->reversal_reason),
            $record instanceof Expense => $this->checked($record, 'expense', $record->status->value, $record->recorded_by, $record->created_at, [
                'approved_at' => $record->getAttribute('approved_at'),
            ], $record->rejection_reason, $record->reversal_reason),
            $record instanceof FundTransfer => $this->checked($record, 'transfer', $record->status->value, $record->recorded_by, $record->created_at, [
                'approved_at' => $record->getAttribute('approved_at'),
            ], $record->rejection_reason, $record->reversal_reason),
            $record instanceof Investment => $this->checked($record, 'investment', match ($record->status) {
                InvestmentStatus::Pending => 'pending',
                InvestmentStatus::Active, InvestmentStatus::Closed => 'approved',
                default => $record->status->value,
            }, $record->recorded_by, $record->created_at, ['approved_at' => $record->getAttribute('approved_at')], $record->rejection_reason, null),
            $record instanceof MemberExit => $this->exit($record),
            $record instanceof RatePlan => $this->ratePlan($record),
            $record instanceof YearEnd => $this->yearEnd($record),
            default => [],
        };
    }

    /**
     * Maker → checker records (payments, expenses, transfers, investments).
     *
     * @param  array<string, mixed>  $times
     * @return list<TimelineStep>
     */
    private function checked(Model $record, string $type, string $status, int $makerId, mixed $enteredAt, array $times, ?string $rejection, ?string $reversal): array
    {
        $steps = [new TimelineStep('entered', __('timeline.entered'), TimelineState::Done, $this->at($enteredAt), $this->name($makerId))];
        $last = $this->at($record->getAttribute('updated_at'));

        $steps[] = match ($status) {
            'pending' => $this->waiting($type, $record, 'approve'),
            'approved', 'reversed' => new TimelineStep('approved', __('timeline.approved'), TimelineState::Done, $this->at($times['approved_at'] ?? null), $this->name($record->getAttribute('approved_by'))),
            'rejected' => new TimelineStep('rejected', __('timeline.rejected'), TimelineState::Rejected, $this->at($times['rejected_at'] ?? null) ?? $last, $this->name($record->getAttribute('rejected_by')), $rejection),
            'cancelled' => new TimelineStep('cancelled', __('timeline.cancelled'), TimelineState::Rejected, $this->at($times['cancelled_at'] ?? null) ?? $last, $this->name($makerId)),
            default => new TimelineStep($status, __('timeline.status', ['status' => $status]), TimelineState::Waiting),
        };

        if ($status === 'reversed') {
            $steps[] = new TimelineStep('reversed', __('timeline.reversed'), TimelineState::Rejected, $this->at($record->getAttribute('reversed_at')), $this->name($record->getAttribute('reversed_by')), $reversal);
        }

        return $steps;
    }

    /**
     * @return list<TimelineStep>
     */
    private function exit(MemberExit $exit): array
    {
        $steps = [new TimelineStep('requested', __('timeline.exit_requested'), TimelineState::Done, $this->at($exit->created_at), $this->name($exit->requested_by), $exit->reason)];

        if ($exit->status === ExitStatus::Cancelled) {
            $steps[] = new TimelineStep('cancelled', __('timeline.cancelled_exit'), TimelineState::Rejected, $this->at($exit->updated_at), null, $exit->cancel_reason);

            return $steps;
        }

        $steps[] = $exit->status === ExitStatus::Requested
            ? $this->waiting('exit', $exit, 'approve')
            : new TimelineStep('approved', __('timeline.exit_approved'), TimelineState::Done, $this->at($exit->approved_at), $this->name($exit->approved_by));

        $steps[] = match ($exit->status) {
            ExitStatus::Paid => new TimelineStep('paid', __('timeline.exit_paid'), TimelineState::Done, $this->at($exit->paid_at), $this->name($exit->paid_by)),
            ExitStatus::Approved => $this->waiting('exit', $exit, 'pay', 'timeline.waiting_payout'),
            default => new TimelineStep('paid', __('timeline.exit_paid'), TimelineState::Waiting),
        };

        return $steps;
    }

    /**
     * @return list<TimelineStep>
     */
    private function ratePlan(RatePlan $plan): array
    {
        $steps = [new TimelineStep('drafted', __('timeline.drafted'), TimelineState::Done, $this->at($plan->created_at), $this->name($plan->created_by))];

        if ($plan->submitted_at === null) {
            return $steps;
        }

        $steps[] = new TimelineStep('submitted', __('timeline.submitted'), TimelineState::Done, $this->at($plan->submitted_at));
        $decisions = $plan->approvals()->where('submission_no', $plan->submission_no)->with('user')->oldest('id')->get();

        foreach ($decisions as $decision) {
            $steps[] = $decision->decision === ApprovalDecision::Approve
                ? new TimelineStep('approval_'.$decision->id, __('timeline.approval_by', ['role' => $decision->role->getLabel()]), TimelineState::Done, $this->at($decision->created_at), $decision->user->name, $decision->comment)
                : new TimelineStep('returned_'.$decision->id, __('timeline.returned_by', ['role' => $decision->role->getLabel()]), TimelineState::Returned, $this->at($decision->created_at), $decision->user->name, $decision->comment);
        }

        if ($plan->status === RatePlanStatus::PendingApproval) {
            $steps[] = $this->waiting('rate_plan', $plan, 'approve');
        }

        if (in_array($plan->status, [RatePlanStatus::Approved, RatePlanStatus::Superseded], true)) {
            $steps[] = new TimelineStep('approved', __('timeline.plan_approved', ['month' => Display::yearMonth($plan->effective_from)]), TimelineState::Done, $this->at($plan->approved_at));
        }

        if ($plan->status === RatePlanStatus::Cancelled) {
            $steps[] = new TimelineStep('cancelled', __('timeline.plan_cancelled'), TimelineState::Rejected, $this->at($plan->updated_at), null, $plan->cancelled_reason);
        }

        return $steps;
    }

    /**
     * @return list<TimelineStep>
     */
    private function yearEnd(YearEnd $yearEnd): array
    {
        $steps = [
            new TimelineStep('prepared', __('timeline.prepared'), TimelineState::Done, $this->at($yearEnd->created_at), $this->name($yearEnd->prepared_by)),
            $yearEnd->president_approved_by === null
                ? new TimelineStep('president', __('timeline.approval_by', ['role' => __('roles.president')]), TimelineState::Waiting)
                : new TimelineStep('president', __('timeline.approval_by', ['role' => __('roles.president')]), TimelineState::Done, $this->at($yearEnd->president_approved_at), $this->name($yearEnd->president_approved_by)),
            $yearEnd->accountant_approved_by === null
                ? new TimelineStep('accountant', __('timeline.approval_by', ['role' => __('roles.accountant')]), TimelineState::Waiting)
                : new TimelineStep('accountant', __('timeline.approval_by', ['role' => __('roles.accountant')]), TimelineState::Done, $this->at($yearEnd->accountant_approved_at), $this->name($yearEnd->accountant_approved_by)),
        ];

        $steps[] = $yearEnd->status === YearEndStatus::Posted
            ? new TimelineStep('posted', __('timeline.posted'), TimelineState::Done, $this->at($yearEnd->posted_at), $this->name($yearEnd->posted_by))
            : $this->waiting('year_end', $yearEnd, 'approve');

        return $steps;
    }

    /**
     * The step that is waiting now, naming who can act — or warning that nobody can.
     */
    private function waiting(string $type, Model $record, string $ability, string $key = 'timeline.waiting_for'): TimelineStep
    {
        $people = $this->queue->whoCanAct(new PendingItem($type, $record, $ability, CarbonImmutable::now()));

        return $people->isEmpty()
            ? new TimelineStep('waiting', __('timeline.nobody_can_act'), TimelineState::Pending)
            : new TimelineStep('waiting', __($key, ['names' => $people->pluck('name')->implode(', ')]), TimelineState::Pending);
    }

    private function name(mixed $userId): ?string
    {
        if (! is_int($userId)) {
            return null;
        }

        return $this->names[$userId] ??= (string) User::query()->whereKey($userId)->value('name');
    }

    private function at(mixed $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::parse($moment);
    }
}
