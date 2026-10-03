<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Contributions\Contracts\AdvanceBalances;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Enums\RegistrationFeePolicy;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Reports\MemberRateImpact;
use App\Domain\Settings\Reports\RateImpactPreview;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Builds the impact preview shown before a rate plan is submitted and approved.
 *
 * "Old" means the approved plan that would otherwise apply to the plan's first month: an
 * approved plan for the same month (which this one would supersede), or the latest earlier one.
 */
final class RateImpactPreviewer
{
    public function __construct(
        private readonly GeneratedMonths $generated,
        private readonly AdvanceBalances $advances,
    ) {}

    public function preview(RatePlan $plan): RateImpactPreview
    {
        $from = $plan->effective_from;
        $previous = $this->previousPlan($plan);

        $next = RatePlan::query()
            ->where('status', RatePlanStatus::Approved)
            ->whereKeyNot($plan->id)
            ->where('effective_from', '>', $from->toDateString())
            ->orderBy('effective_from')
            ->first();

        $newPerShare = $plan->share_unit_poisha->plus($plan->service_charge_per_share_poisha);
        $oldPerShare = $previous === null
            ? Money::zero()
            : $previous->share_unit_poisha->plus($previous->service_charge_per_share_poisha);

        $advances = $this->advances->all();
        $members = [];

        foreach ($this->sharesByMember($from->toDateString()) as $row) {
            $members[] = new MemberRateImpact(
                memberId: (int) $row->member_id,
                memberNo: (string) $row->member_no,
                name: app()->getLocale() === 'bn' ? (string) $row->name_bn : (string) $row->name_en,
                shares: (int) $row->shares,
                oldMonthly: $oldPerShare->multipliedByInt((int) $row->shares),
                newMonthly: $newPerShare->multipliedByInt((int) $row->shares),
                advance: $advances[(int) $row->member_id] ?? Money::zero(),
            );
        }

        $locked = Due::query()
            ->where('prepaid_locked', true)
            ->where('month', '>=', $from->toDateString())
            ->when($next !== null, fn ($query) => $query->where('month', '<', $next?->effective_from->toDateString()))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_poisha), 0)::bigint AS total')
            ->first();

        [$topUpLots, $topUpAmount] = $this->registrationTopUp($plan, $previous);

        return new RateImpactPreview(
            plan: $plan,
            previous: $previous,
            from: $from,
            until: $next?->effective_from->previous(),
            members: $members,
            lockedDueCount: (int) ($locked->n ?? 0),
            lockedDueAmount: Money::ofPoisha((int) ($locked->total ?? 0)),
            topUpLotCount: $topUpLots,
            topUpAmount: $topUpAmount,
            warnings: $this->warnings($plan, $previous),
        );
    }

    private function previousPlan(RatePlan $plan): ?RatePlan
    {
        return RatePlan::query()
            ->where('status', RatePlanStatus::Approved)
            ->whereKeyNot($plan->id)
            ->where('effective_from', '<=', $plan->effective_from->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Active members with their share count in the month, from the share timeline.
     *
     * @return Collection<int, stdClass> rows of member_id, member_no, name_bn, name_en, shares
     */
    private function sharesByMember(string $month): Collection
    {
        return DB::table('members as m')
            ->joinSub(
                DB::table('member_share_snapshots')
                    ->selectRaw('DISTINCT ON (member_id) member_id, shares')
                    ->where('effective_from', '<=', $month)
                    ->orderBy('member_id')
                    ->orderByDesc('effective_from'),
                's',
                's.member_id',
                '=',
                'm.id',
            )
            ->where('m.status', MemberStatus::Active->value)
            ->whereNull('m.deleted_at')
            ->where('s.shares', '>', 0)
            ->orderBy('m.member_no')
            ->get(['m.id as member_id', 'm.member_no', 'm.name_bn', 'm.name_en', 's.shares']);
    }

    /**
     * Mirrors RegistrationFees::topUpForPlan so the preview and the approval agree.
     *
     * @return array{0: int, 1: Money}
     */
    private function registrationTopUp(RatePlan $plan, ?RatePlan $previous): array
    {
        if ($plan->registration_fee_on_rate_increase !== RegistrationFeePolicy::Difference || $previous === null) {
            return [0, Money::zero()];
        }

        $base = $previous->effective_from->equals($plan->effective_from)
            ? RatePlan::query()
                ->where('status', RatePlanStatus::Approved)
                ->where('effective_from', '<', $plan->effective_from->toDateString())
                ->orderByDesc('effective_from')
                ->first()
            : $previous;

        if ($base === null) {
            return [0, Money::zero()];
        }

        $difference = $plan->registration_fee_per_share_poisha->minus($base->registration_fee_per_share_poisha);

        if (! $difference->isPositive()) {
            return [0, Money::zero()];
        }

        $month = $plan->effective_from->toDateString();

        $lots = ShareLot::query()
            ->where('effective_from', '<', $month)
            ->where(fn ($query) => $query->whereNull('ended_from')->orWhere('ended_from', '>', $month))
            ->whereHas('member', fn ($query) => $query->where('status', MemberStatus::Active))
            ->get(['shares']);

        return [$lots->count(), $difference->multipliedByInt((int) $lots->sum('shares'))];
    }

    /**
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    private function warnings(RatePlan $plan, ?RatePlan $previous): array
    {
        $warnings = [];
        $generated = $this->generated->latest();

        if ($generated !== null && $plan->effective_from->isSameOrBefore($generated)) {
            $warnings[] = ['key' => $plan->is_retroactive ? 'rates.impact.warnings.retroactive' : 'rates.impact.warnings.month_generated', 'params' => ['latest' => (string) $generated]];
        }

        if ($plan->late_fee_mode === LateFeeMode::Percent && $plan->late_fee_cap_poisha === null) {
            $warnings[] = ['key' => 'rates.impact.warnings.percent_without_cap', 'params' => []];
        }

        if ($previous === null) {
            $warnings[] = ['key' => 'rates.impact.warnings.first_plan', 'params' => []];
        } elseif ($previous->effective_from->equals($plan->effective_from)) {
            $warnings[] = ['key' => 'rates.impact.warnings.supersedes', 'params' => ['code' => $previous->code]];
        }

        if ($previous !== null && $plan->share_unit_poisha->isLessThan($previous->share_unit_poisha)) {
            $warnings[] = ['key' => 'rates.impact.warnings.deposit_decrease', 'params' => []];
        }

        return $warnings;
    }
}
