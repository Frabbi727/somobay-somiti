<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Settings\Enums\LateFeeBase;
use App\Domain\Settings\Enums\LateFeeFrequency;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Carbon\CarbonImmutable;

/**
 * Late fee arithmetic (BR-10/11), driven by the rate snapshot copied onto the due.
 */
final class LateFeeCalculator
{
    /**
     * The first day a due counts as late: due date + grace days + 1.
     */
    public function lateFrom(CarbonImmutable $dueDate, int $graceDays): CarbonImmutable
    {
        return $dueDate->startOfDay()->addDays($graceDays + 1);
    }

    /**
     * How many late-fee charges should exist by $today: none before lateFrom; then one, plus one per
     * further full month for "monthly until paid".
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function chargesDue(array $snapshot, CarbonImmutable $dueDate, CarbonImmutable $today): int
    {
        $mode = LateFeeMode::tryFrom((string) ($snapshot['late_fee_mode'] ?? 'none'));

        if ($mode === null || $mode === LateFeeMode::None) {
            return 0;
        }

        $lateFrom = $this->lateFrom($dueDate, (int) ($snapshot['grace_days'] ?? 0));
        $today = $today->startOfDay();

        if ($today->lessThan($lateFrom)) {
            return 0;
        }

        if (LateFeeFrequency::tryFrom((string) ($snapshot['late_fee_frequency'] ?? '')) !== LateFeeFrequency::MonthlyUntilPaid) {
            return 1;
        }

        return 1 + (int) $lateFrom->diffInMonths($today);
    }

    /**
     * The charge date of the n-th late fee (0-based).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function chargeDate(array $snapshot, CarbonImmutable $dueDate, int $index): CarbonImmutable
    {
        return $this->lateFrom($dueDate, (int) ($snapshot['grace_days'] ?? 0))->addMonthsNoOverflow($index);
    }

    /**
     * The base a percentage late fee is charged on, from one member's dues for one month.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  iterable<Due>  $memberMonthDues
     */
    public function base(array $snapshot, iterable $memberMonthDues): Money
    {
        $types = match (LateFeeBase::tryFrom((string) ($snapshot['late_fee_base'] ?? ''))) {
            LateFeeBase::DepositOnly, null => [DueType::Deposit],
            LateFeeBase::DepositPlusService => [DueType::Deposit, DueType::ServiceCharge],
            LateFeeBase::OutstandingTotal => [DueType::Deposit, DueType::ServiceCharge, DueType::Registration],
        };

        $base = Money::zero();

        foreach ($memberMonthDues as $due) {
            if (in_array($due->type, $types, true)) {
                $base = $base->plus($due->outstanding_poisha);
            }
        }

        return $base;
    }

    /**
     * One charge: the fixed amount, or HALF_UP bps of the base; a cap limits the running total.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function fee(array $snapshot, Money $base, Money $alreadyCharged): Money
    {
        $fee = match (LateFeeMode::tryFrom((string) ($snapshot['late_fee_mode'] ?? 'none'))) {
            LateFeeMode::Fixed => Money::ofPoisha((int) ($snapshot['late_fee_fixed_poisha'] ?? 0)),
            LateFeeMode::Percent => Money::ofPoisha(Rounding::halfUpBps($base->poisha, (int) ($snapshot['late_fee_bps'] ?? 0))),
            default => Money::zero(),
        };

        $cap = $snapshot['late_fee_cap_poisha'] ?? null;

        if ($cap !== null) {
            $fee = $fee->min(Money::ofPoisha((int) $cap)->minus($alreadyCharged)->max(Money::zero()));
        }

        return $fee->max(Money::zero());
    }
}
