<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Member-facing registers: statement, collection summary, defaulters and the share register.
 */
final class MemberReports
{
    /**
     * Charges (dues) and money received (approved payments) in date order, with a running balance
     * owed (negative = paid ahead).
     *
     * @return array{opening: Money, rows: list<array{date: CarbonImmutable, description: string, charge: Money, paid: Money, balance: Money}>, total_charges: Money, total_paid: Money, closing: Money}
     */
    public function statement(Member $member, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $charges = fn (?string $after, ?string $through) => Due::query()
            ->where('member_id', $member->id)
            ->whereNotIn('status', [DueStatus::Cancelled, DueStatus::Waived])
            ->when($after !== null, fn ($query) => $query->where('due_date', '>=', $after))
            ->when($through !== null, fn ($query) => $query->where('due_date', '<=', $through));

        $payments = fn (?string $after, ?string $through) => Payment::query()
            ->where('member_id', $member->id)
            ->where('status', PaymentStatus::Approved)
            ->when($after !== null, fn ($query) => $query->where('received_on', '>=', $after))
            ->when($through !== null, fn ($query) => $query->where('received_on', '<=', $through));

        $before = $from->subDay()->toDateString();
        $opening = Money::ofPoisha((int) $charges(null, $before)->sum('amount_poisha') - (int) $payments(null, $before)->sum('amount_poisha'));

        $events = [];

        foreach ($charges($from->toDateString(), $until->toDateString())->orderBy('due_date')->orderBy('id')->get() as $due) {
            $events[] = [$due->due_date, 0, $due->id, $due->type->getLabel().' · '.(string) $due->month, $due->amount_poisha, Money::zero()];
        }

        foreach ($payments($from->toDateString(), $until->toDateString())->with('journalEntry')->orderBy('received_on')->orderBy('id')->get() as $payment) {
            $events[] = [$payment->received_on, 1, $payment->id, ($payment->journalEntry->voucher_no ?? '').' · '.$payment->method->getLabel(), Money::zero(), $payment->amount_poisha];
        }

        usort($events, fn (array $a, array $b): int => [$a[0]->toDateString(), $a[1], $a[2]] <=> [$b[0]->toDateString(), $b[1], $b[2]]);

        $balance = $opening;
        $rows = [];

        foreach ($events as [$date, , , $description, $charge, $paid]) {
            $balance = $balance->plus($charge)->minus($paid);
            $rows[] = ['date' => $date, 'description' => $description, 'charge' => $charge, 'paid' => $paid, 'balance' => $balance];
        }

        return [
            'opening' => $opening,
            'rows' => $rows,
            'total_charges' => Money::sum(array_column($rows, 'charge')),
            'total_paid' => Money::sum(array_column($rows, 'paid')),
            'closing' => $balance,
        ];
    }

    /**
     * Per month: charged, paid (by payments or advance) and still outstanding.
     *
     * @return list<array{month: YearMonth, members: int, charged: Money, paid: Money, outstanding: Money}>
     */
    public function collectionSummary(YearMonth $from, YearMonth $until): array
    {
        $rows = DB::table('dues')
            ->whereBetween('month', [$from->toDateString(), $until->toDateString()])
            ->whereNotIn('status', [DueStatus::Cancelled->value, DueStatus::Waived->value])
            ->groupBy('month')
            ->orderBy('month')
            ->selectRaw('month, COUNT(DISTINCT member_id) AS members, SUM(amount_poisha)::bigint AS charged, SUM(paid_poisha)::bigint AS paid, SUM(outstanding_poisha)::bigint AS outstanding')
            ->get();

        return array_values($rows->map(fn (object $row): array => [
            'month' => YearMonth::parse(substr((string) $row->month, 0, 10)),
            'members' => (int) $row->members,
            'charged' => Money::ofPoisha((int) $row->charged),
            'paid' => Money::ofPoisha((int) $row->paid),
            'outstanding' => Money::ofPoisha((int) $row->outstanding),
        ])->all());
    }

    /**
     * Members with dues unpaid past their due date, largest outstanding first.
     *
     * @return list<array{member: Member, months: int, oldest: YearMonth, outstanding: Money}>
     */
    public function defaulters(CarbonImmutable $asOf, int $minMonths = 1): array
    {
        $rows = DB::table('dues')
            ->where('status', DueStatus::Open->value)
            ->where('outstanding_poisha', '>', 0)
            ->where('due_date', '<', $asOf->toDateString())
            ->groupBy('member_id')
            ->havingRaw('COUNT(DISTINCT month) >= ?', [$minMonths])
            ->orderByRaw('SUM(outstanding_poisha) DESC')
            ->selectRaw('member_id, COUNT(DISTINCT month) AS months, MIN(month) AS oldest, SUM(outstanding_poisha)::bigint AS outstanding')
            ->get();

        $members = Member::query()->whereIn('id', $rows->pluck('member_id')->all())->get()->keyBy('id');
        $result = [];

        foreach ($rows as $row) {
            $member = $members->get((int) $row->member_id);

            if ($member !== null) {
                $result[] = [
                    'member' => $member,
                    'months' => (int) $row->months,
                    'oldest' => YearMonth::parse(substr((string) $row->oldest, 0, 10)),
                    'outstanding' => Money::ofPoisha((int) $row->outstanding),
                ];
            }
        }

        return $result;
    }

    /**
     * Shares held by each member in a month, from the share timeline.
     *
     * @return list<array{member: Member, shares: int}>
     */
    public function shareRegister(YearMonth $month): array
    {
        $shares = DB::table('member_share_snapshots')
            ->selectRaw('DISTINCT ON (member_id) member_id, shares')
            ->where('effective_from', '<=', $month->toDateString())
            ->orderBy('member_id')
            ->orderByDesc('effective_from')
            ->get()
            ->filter(fn (object $row): bool => (int) $row->shares > 0)
            ->pluck('shares', 'member_id');

        return array_values(Member::query()
            ->whereIn('id', $shares->keys()->all())
            ->where('status', '!=', MemberStatus::Exited)
            ->orderBy('member_no')
            ->get()
            ->map(fn (Member $member): array => ['member' => $member, 'shares' => (int) $shares->get($member->id)])
            ->all());
    }
}
