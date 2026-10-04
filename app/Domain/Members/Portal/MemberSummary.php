<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Accounting\AccountCode;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Domain\Members\Models\Member;
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
    ) {}

    /**
     * @return array{savings: Money, advance: Money, outstanding: Money, paid_through: YearMonth|null, estimate: int, shares: int}
     */
    public function for(Member $member): array
    {
        $savings = (int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', AccountCode::MEMBER_SAVINGS)
            ->where('l.member_id', $member->id)
            ->sum(DB::raw('l.credit_poisha - l.debit_poisha'));

        return [
            'savings' => Money::ofPoisha($savings),
            'advance' => $this->advances->balance($member->id),
            'outstanding' => Money::ofPoisha((int) Due::query()
                ->where('member_id', $member->id)
                ->where('status', DueStatus::Open)
                ->sum('outstanding_poisha')),
            'paid_through' => $this->paidThrough->for($member),
            'estimate' => $this->paidThrough->estimatedMonths($member),
            'shares' => $member->sharesIn(YearMonth::current()),
        ];
    }
}
