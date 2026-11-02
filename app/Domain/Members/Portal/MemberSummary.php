<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Accounting\AccountCode;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Services\RateResolver;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\DB;

/**
 * The figures on a member's portal dashboard.
 */
final class MemberSummary
{
    public function __construct(
        private readonly AdvanceLedger $advances,
        private readonly PaidThroughCalculator $paidThrough,
        private readonly RateResolver $rates,
    ) {}

    /**
     * Every due figure comes from the dues themselves, so they always add up:
     * billed − paid − waived = outstanding (this month + earlier months + any later month).
     *
     * @return array{savings: Money, advance: Money, outstanding: Money, paid_through: YearMonth|null, estimate: int, shares: int, billed: Money, paid: Money, waived: Money, due_this_month: Money, due_earlier: Money, monthly_deposit: Money|null}
     */
    public function for(Member $member): array
    {
        $savings = (int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', AccountCode::MEMBER_SAVINGS)
            ->where('l.member_id', $member->id)
            ->sum(DB::raw('l.credit_poisha - l.debit_poisha'));

        $current = YearMonth::current();
        $shares = $member->sharesIn($current);

        $dues = DB::table('dues')
            ->where('member_id', $member->id)
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(amount_poisha) FILTER (WHERE status <> 'cancelled'), 0)::bigint AS billed,
                COALESCE(SUM(paid_poisha) FILTER (WHERE status <> 'cancelled'), 0)::bigint AS paid,
                COALESCE(SUM(amount_poisha - paid_poisha) FILTER (WHERE status = 'waived'), 0)::bigint AS waived,
                COALESCE(SUM(outstanding_poisha) FILTER (WHERE status = 'open' AND month = ?), 0)::bigint AS this_month,
                COALESCE(SUM(outstanding_poisha) FILTER (WHERE status = 'open' AND month < ?), 0)::bigint AS earlier
                SQL, [$current->toDateString(), $current->toDateString()])
            ->first();

        try {
            $monthlyDeposit = $this->rates->for($current)->share_unit_poisha->multipliedByInt($shares);
        } catch (NoRatePlanForMonth) {
            $monthlyDeposit = null;
        }

        return [
            'billed' => Money::ofPoisha((int) ($dues->billed ?? 0)),
            'paid' => Money::ofPoisha((int) ($dues->paid ?? 0)),
            'waived' => Money::ofPoisha((int) ($dues->waived ?? 0)),
            'due_this_month' => Money::ofPoisha((int) ($dues->this_month ?? 0)),
            'due_earlier' => Money::ofPoisha((int) ($dues->earlier ?? 0)),
            'monthly_deposit' => $monthlyDeposit,
            'savings' => Money::ofPoisha($savings),
            'advance' => $this->advances->balance($member->id),
            'outstanding' => Money::ofPoisha((int) Due::query()
                ->where('member_id', $member->id)
                ->where('status', DueStatus::Open)
                ->sum('outstanding_poisha')),
            'paid_through' => $this->paidThrough->for($member),
            'estimate' => $this->paidThrough->estimatedMonths($member),
            'shares' => $shares,
        ];
    }
}
