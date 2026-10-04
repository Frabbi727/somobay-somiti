<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Headline figures for the dashboard.
 */
final class SomitiSnapshot
{
    public function activeMembers(): int
    {
        return Member::query()->where('status', MemberStatus::Active)->count();
    }

    public function membersOverdue(CarbonImmutable $today): int
    {
        return Member::query()
            ->whereHas('dues', fn ($query) => $query->where('status', DueStatus::Open)->where('due_date', '<', $today->toDateString()))
            ->count();
    }

    public function collectedIn(YearMonth $month): Money
    {
        return Money::ofPoisha((int) DB::table('payments')
            ->where('status', PaymentStatus::Approved->value)
            ->whereBetween('received_on', [$month->firstDay()->toDateString(), $month->lastDay()->toDateString()])
            ->sum('amount_poisha'));
    }

    /**
     * Debit-positive balance of an account (assets); pass $credit for liabilities.
     */
    public function balance(string $code, bool $credit = false): Money
    {
        $net = (int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->sum(DB::raw('l.debit_poisha - l.credit_poisha'));

        return Money::ofPoisha($credit ? -$net : $net);
    }
}
