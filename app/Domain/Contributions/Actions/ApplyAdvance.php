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
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\AllocationEngine;
use App\Domain\Contributions\Services\DuePosting;
use App\Domain\Members\Models\Member;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\SystemUser;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BR-14: applies members' advance balances to their open dues, oldest first, at whatever amount
 * each due carries (default policy apply_at_current_rate). One JV per member: Dr 2111, Cr the
 * dues' accounts. Runs after dues generation and late fees.
 */
final class ApplyAdvance
{
    public function __construct(
        private readonly AdvanceLedger $advances,
        private readonly AllocationEngine $engine,
        private readonly DuePosting $posting,
        private readonly Accounts $accounts,
        private readonly PostJournal $post,
    ) {}

    /**
     * @param  list<int>|null  $memberIds  null = every member holding an advance
     * @return array{members: int, total: Money}
     */
    public function __invoke(?array $memberIds = null, ?User $actor = null, ?CarbonImmutable $date = null): array
    {
        $actor ??= SystemUser::get();
        $date ??= CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay();
        $candidates = array_keys($this->advances->all());

        if ($memberIds !== null) {
            $candidates = array_values(array_intersect($candidates, $memberIds));
        }

        sort($candidates);
        $members = 0;
        $total = Money::zero();

        foreach ($candidates as $memberId) {
            $applied = DB::transaction(fn (): Money => $this->applyFor($memberId, $actor, $date), attempts: 3);

            if ($applied->isPositive()) {
                $members++;
                $total = $total->plus($applied);
            }
        }

        return ['members' => $members, 'total' => $total];
    }

    private function applyFor(int $memberId, User $actor, CarbonImmutable $date): Money
    {
        $member = Member::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();

        $dues = Due::query()
            ->where('member_id', $member->id)
            ->where('status', DueStatus::Open)
            ->where('outstanding_poisha', '>', 0)
            ->where('due_date', '<=', $date->endOfMonth()->toDateString())
            ->orderBy('month')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $balance = $this->advances->balance($member->id);

        if ($dues->isEmpty() || ! $balance->isPositive()) {
            return Money::zero();
        }

        ['allocations' => $allocations, 'remainder' => $remainder] = $this->engine->allocate($balance, $dues);
        $applied = $balance->minus($remainder);

        if (! $applied->isPositive()) {
            return Money::zero();
        }

        $entry = ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Journal,
            entryDate: $date,
            narration: 'Advance applied '.$member->member_no,
            lines: [
                JournalLineData::debit($this->accounts->byCode(AccountCode::MEMBER_ADVANCE), $applied, $member->id),
                ...$this->posting->creditLines($allocations, $member->id),
            ],
            source: $member,
        ));

        foreach ($allocations as ['due' => $due, 'amount' => $amount]) {
            $this->posting->settle($due, $amount);
            $this->advances->append($member->id, AdvanceEntryKind::AppliedToDue, $amount->negated(), [
                'due_id' => $due->id,
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);
        }

        return $applied;
    }
}
