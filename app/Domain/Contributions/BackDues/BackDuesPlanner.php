<?php

declare(strict_types=1);

namespace App\Domain\Contributions\BackDues;

use App\Domain\GoLive\Services\GoLiveMonth;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\FinancialSettings;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/**
 * Works out a late joiner's back-dues: for every month from the dues start month up to the month
 * before their shares start, that month's deposit and service charge at that month's approved
 * rate; plus an entry premium of the setting's percentage of the back deposits, rounded HALF_UP
 * once (§6.2). Used both for the preview and inside CreateMember.
 */
final class BackDuesPlanner
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly FinancialSettings $settings,
        private readonly GoLiveMonth $goLive,
    ) {}

    public function plan(int $shares, YearMonth $duesStart, YearMonth $shareStart): BackDuesPlan
    {
        if ($duesStart->isAfter($shareStart)) {
            throw DomainRuleViolation::because('members.errors.dues_start_after_shares', ['month' => (string) $shareStart]);
        }

        // Only a late joiner (dues before shares) needs the society-start check; a normal joiner
        // keeps the existing "no approved rate plan" error from the share change.
        $societyStart = $duesStart->isBefore($shareStart) ? $this->societyStart() : null;

        if ($societyStart !== null && $duesStart->isBefore($societyStart)) {
            throw DomainRuleViolation::because('members.errors.dues_start_before_society', ['month' => (string) $societyStart]);
        }

        $settings = $this->settings->current();
        $months = [];

        for ($month = $duesStart; $month->isBefore($shareStart); $month = $month->next()) {
            $plan = $this->rates->find($month)
                ?? throw DomainRuleViolation::because('members.errors.no_rate_plan', ['month' => (string) $month]);

            $months[] = [
                'month' => $month,
                'plan' => $plan,
                'deposit' => $plan->share_unit_poisha->multipliedByInt($shares),
                'serviceCharge' => $plan->service_charge_per_share_poisha->multipliedByInt($shares),
            ];
        }

        if ($months !== [] && ! $settings->backDuesEnabled) {
            throw DomainRuleViolation::because('members.errors.back_dues_disabled');
        }

        $joiningPlan = $this->rates->find($shareStart);
        $lastBackPlan = $months === [] ? null : $months[count($months) - 1]['plan'];
        $rate = $months === [] ? Bps::zero() : $settings->entryPremiumRate();
        $deposits = Money::sum(array_map(fn (array $row): Money => $row['deposit'], $months));

        return new BackDuesPlan(
            duesStart: $duesStart,
            shareStart: $shareStart,
            shares: $shares,
            months: $months,
            registrationFee: $joiningPlan?->registration_fee_per_share_poisha->multipliedByInt($shares) ?? Money::zero(),
            premiumRate: $rate,
            premium: $deposits->percentOfBps($rate),
            premiumPlan: $joiningPlan ?? $lastBackPlan,
        );
    }

    /**
     * The first month the society billed in this app: the go-live month, or the first approved
     * rate plan when the society started in the app. Null before any plan is approved.
     */
    public function societyStart(): ?YearMonth
    {
        $goLive = $this->goLive->get();

        if ($goLive !== null) {
            return $goLive;
        }

        return RatePlan::query()->where('status', RatePlanStatus::Approved)->orderBy('effective_from')->first()?->effective_from;
    }
}
