<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Events\PaymentApproved;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\AllocationEngine;
use App\Domain\Contributions\Services\DuePosting;
use App\Domain\Contributions\Services\PrepaidLocker;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Enums\AdvancePolicy;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W3 (checker), §6.4: approves a pending payment and posts it.
 *
 * Lock order: payment → member → dues → advance ledger → voucher sequence. The payment row lock
 * plus the status check make a second, simultaneous approval see "approved" and stop, so a payment
 * is posted exactly once. Allocation: oldest open dues first; under lock_prepaid_months the rest
 * prepays whole future months at the current rate; anything left becomes advance (2111).
 */
final class ApprovePayment
{
    public function __construct(
        private readonly AllocationEngine $engine,
        private readonly DuePosting $posting,
        private readonly AdvanceLedger $advances,
        private readonly PrepaidLocker $locker,
        private readonly RateResolver $rates,
        private readonly Accounts $accounts,
        private readonly PostJournal $post,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Payment $payment): Payment
    {
        return $this->causer->withCauser($actor, fn (): Payment => DB::transaction(function () use ($actor, $payment): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                throw DomainRuleViolation::because('payments.errors.already_processed', ['status' => $locked->status->getLabel()]);
            }

            Gate::forUser($actor)->authorize('approve', $locked);

            $member = Member::query()->whereKey($locked->member_id)->lockForUpdate()->firstOrFail();
            $month = YearMonth::fromDate($locked->received_on);
            $plan = $this->rates->find($month) ?? $this->rates->find(YearMonth::current());
            $policy = $plan->advance_policy ?? AdvancePolicy::ApplyAtCurrentRate;

            $openDues = Due::query()
                ->where('member_id', $member->id)
                ->where('status', DueStatus::Open)
                ->where('outstanding_poisha', '>', 0)
                ->orderBy('month')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            ['allocations' => $allocations, 'remainder' => $remainder] = $this->engine->allocate($locked->amount_poisha, $openDues);

            if ($remainder->isPositive() && $policy === AdvancePolicy::LockPrepaidMonths && $plan !== null) {
                $lastMonth = Due::query()->where('member_id', $member->id)->where('type', DueType::Deposit)->max('month');
                $after = $lastMonth === null ? $month : YearMonth::parse(substr((string) $lastMonth, 0, 10))->max($month);
                $lockedDues = $this->locker->lock($member, $plan, $remainder, $after);

                ['allocations' => $prepaid, 'remainder' => $remainder] = $this->engine->allocate($remainder, collect($lockedDues));
                $allocations = [...$allocations, ...$prepaid];
            }

            $lines = [
                JournalLineData::debit($this->accounts->byCode($locked->method->accountCode()), $locked->amount_poisha),
                ...$this->posting->creditLines($allocations, $member->id),
            ];

            if ($remainder->isPositive()) {
                $lines[] = JournalLineData::credit($this->accounts->byCode(AccountCode::MEMBER_ADVANCE), $remainder, $member->id);
            }

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Receipt,
                entryDate: $locked->received_on,
                narration: trim(sprintf('Collection %s · %s %s', $member->member_no, $locked->method->value, (string) $locked->trx_id)),
                lines: $lines,
                source: $locked,
            ));

            foreach ($allocations as ['due' => $due, 'amount' => $amount]) {
                PaymentAllocation::query()->create(['payment_id' => $locked->id, 'due_id' => $due->id, 'amount_poisha' => $amount]);
                $this->posting->settle($due, $amount);
            }

            if ($remainder->isPositive()) {
                $this->advances->append($member->id, AdvanceEntryKind::PaymentSurplus, $remainder, [
                    'payment_id' => $locked->id,
                    'journal_entry_id' => $entry->id,
                    'created_by' => $actor->id,
                ]);
            }

            $locked->forceFill([
                'status' => PaymentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
                'journal_entry_id' => $entry->id,
                'advance_policy_at_payment' => $policy,
            ])->save();

            event(new PaymentApproved($locked));

            return $locked;
        }, attempts: 3));
    }

    /**
     * Total settled by a list of allocations (for previews).
     *
     * @param  list<array{due: Due, amount: Money}>  $allocations
     */
    public static function allocatedTotal(array $allocations): Money
    {
        return Money::sum(array_column($allocations, 'amount'));
    }
}
