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
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * BR-16: pays a member's unused advance back (PV: Dr 2111 / Cr cash or bank).
 * Refunds at or above the configured threshold need the president.
 */
final class RefundAdvance
{
    public function __construct(
        private readonly AdvanceLedger $advances,
        private readonly Accounts $accounts,
        private readonly PostJournal $post,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Member $member, Money $amount, PaymentMethod $paidFrom, string $reason): AdvanceLedgerEntry
    {
        Gate::forUser($actor)->authorize('refundAdvance');

        if (! $amount->isPositive()) {
            throw DomainRuleViolation::because('payments.errors.amount_positive');
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw DomainRuleViolation::because('payments.errors.reason_required');
        }

        $threshold = Money::ofPoisha((int) config('somiti.advance_refund_president_threshold_poisha'));

        if ($amount->isGreaterThanOrEqualTo($threshold) && ! $actor->hasAnyOf(Role::President)) {
            throw DomainRuleViolation::because('payments.errors.refund_needs_president', ['threshold' => $threshold->format(app()->getLocale())]);
        }

        return $this->causer->withCauser($actor, fn (): AdvanceLedgerEntry => DB::transaction(function () use ($actor, $member, $amount, $paidFrom, $reason): AdvanceLedgerEntry {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            $balance = $this->advances->balance($locked->id);

            if ($amount->isGreaterThan($balance)) {
                throw DomainRuleViolation::because('payments.errors.refund_exceeds_advance', ['balance' => $balance->format(app()->getLocale())]);
            }

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Payment,
                entryDate: CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay(),
                narration: 'Advance refund '.$locked->member_no,
                lines: [
                    JournalLineData::debit($this->accounts->byCode(AccountCode::MEMBER_ADVANCE), $amount, $locked->id),
                    JournalLineData::credit($this->accounts->byCode($paidFrom->accountCode()), $amount),
                ],
                source: $locked,
                reason: trim($reason),
            ));

            return $this->advances->append($locked->id, AdvanceEntryKind::Refund, $amount->negated(), [
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);
        }, attempts: 3));
    }
}
