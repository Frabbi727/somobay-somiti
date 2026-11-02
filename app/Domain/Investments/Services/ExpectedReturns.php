<?php

declare(strict_types=1);

namespace App\Domain\Investments\Services;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\ReturnFrequency;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentIncome;
use App\Domain\Investments\Models\InvestmentReturnExpectation;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

/**
 * T-12: what an investment should have paid by a date under its expected returns, against the
 * income actually received (gross, vouchers not reversed). Tracking only — nothing is accrued.
 *
 * Each expectation applies from its start date until the next one starts; a newer row with the same
 * start date replaces an older one. Payment dates step from the start date (31 Jan → 28 Feb → 31 Mar).
 *
 * @phpstan-type ReturnSummary array{current: ?InvestmentReturnExpectation, periods: int, expected: Money, received: Money, difference: Money, behind_periods: int, last_received_on: ?CarbonImmutable, next_due: ?array{due_on: CarbonImmutable, amount: Money}}
 */
final class ExpectedReturns
{
    /**
     * @return list<InvestmentReturnExpectation>
     */
    public function effective(Investment $investment): array
    {
        return array_values($investment->returnExpectations()
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (InvestmentReturnExpectation $row): string => $row->starts_on->toDateString())
            ->all());
    }

    /**
     * Expected payments due on or before $until.
     *
     * @return list<array{due_on: CarbonImmutable, amount: Money}>
     */
    public function schedule(Investment $investment, CarbonImmutable $until): array
    {
        $rows = $this->effective($investment);
        $until = $until->startOfDay();
        $end = $this->endOf($investment);

        if ($end !== null && $end->isBefore($until)) {
            $until = $end;
        }

        $schedule = [];

        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1] ?? null;
            $stop = $next?->starts_on;
            $months = $row->frequency->months();

            if ($row->frequency === ReturnFrequency::None) {
                continue;
            }

            for ($step = 0; ; $step++) {
                $due = $months === null ? $row->starts_on : $row->starts_on->addMonthsNoOverflow($step * $months);

                if ($due->isAfter($until) || ($stop !== null && ! $due->isBefore($stop)) || ($months === null && $step > 0)) {
                    break;
                }

                $schedule[] = ['due_on' => $due, 'amount' => $row->amount_poisha];
            }
        }

        return $schedule;
    }

    /**
     * @return ReturnSummary
     */
    public function summary(Investment $investment, CarbonImmutable $asOf): array
    {
        $asOf = $asOf->startOfDay();
        $rows = $this->effective($investment);
        $current = null;

        foreach ($rows as $row) {
            if (! $row->starts_on->isAfter($asOf)) {
                $current = $row;
            }
        }

        $due = $this->schedule($investment, $asOf);
        $expected = Money::sum(array_map(fn (array $period): Money => $period['amount'], $due));
        $received = Money::zero();
        $lastReceived = null;

        if ($rows !== []) {
            $end = $this->endOf($investment);
            $upTo = $end !== null && $end->isBefore($asOf) ? $end : $asOf;
            $income = InvestmentIncome::query()
                ->where('investment_id', $investment->id)
                ->whereBetween('received_on', [$rows[0]->starts_on->toDateString(), $upTo->toDateString()])
                ->whereDoesntHave('journalEntry.reversal')
                ->get();

            $received = Money::sum($income->map(fn (InvestmentIncome $row): Money => $row->gross_poisha));
            $lastReceived = $income->max(fn (InvestmentIncome $row): string => $row->received_on->toDateString());
        }

        $difference = $received->minus($expected);
        $periodic = $current !== null && $current->amount_poisha->isPositive() ? $current->amount_poisha : null;

        return [
            'current' => $current,
            'periods' => count($due),
            'expected' => $expected,
            'received' => $received,
            'difference' => $difference,
            'behind_periods' => $difference->isNegative() && $periodic !== null ? intdiv($difference->absolute()->poisha, $periodic->poisha) : 0,
            'last_received_on' => $lastReceived === null ? null : CarbonImmutable::parse($lastReceived),
            'next_due' => $this->nextDue($investment, $asOf),
        ];
    }

    /**
     * @return array{due_on: CarbonImmutable, amount: Money}|null
     */
    private function nextDue(Investment $investment, CarbonImmutable $asOf): ?array
    {
        if ($investment->status !== InvestmentStatus::Active) {
            return null;
        }

        foreach ($this->schedule($investment, $asOf->addMonths(13)) as $period) {
            if ($period['due_on']->isAfter($asOf)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Nothing more is expected after an investment is sold or reversed.
     */
    private function endOf(Investment $investment): ?CarbonImmutable
    {
        return match ($investment->status) {
            InvestmentStatus::Closed => $investment->closed_on,
            InvestmentStatus::Reversed => $investment->reversed_at?->setTimezone(YearMonth::TIMEZONE)->startOfDay(),
            default => null,
        };
    }
}
