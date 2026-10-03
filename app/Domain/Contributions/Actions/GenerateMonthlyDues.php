<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Events\MonthlyDuesGenerated;
use App\Domain\Contributions\Reports\DueGenerationPlan;
use App\Domain\Contributions\Services\MonthlyDueBuilder;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * W2: creates a month's deposit and service-charge dues for every active member.
 *
 * Idempotent: rows are inserted with insertOrIgnore on the dues natural key, so running a month
 * again only adds what is missing. A cache lock per month keeps two runs from overlapping, and
 * members are processed in chunks, each in its own transaction.
 */
final class GenerateMonthlyDues
{
    public const int CHUNK = 200;

    public function __construct(
        private readonly MonthlyDueBuilder $builder,
        private readonly RateResolver $rates,
        private readonly GeneratedMonths $generated,
    ) {}

    /**
     * What a run would create right now, without writing anything.
     */
    public function preview(YearMonth $month): DueGenerationPlan
    {
        $plan = $this->planFor($month);
        $rows = $this->builder->rows($month, $plan, $this->builder->memberIds($month)->all());

        $existing = DB::table('dues')
            ->where('month', $month->toDateString())
            ->whereIn('type', [DueType::Deposit->value, DueType::ServiceCharge->value])
            ->get(['type', 'share_lot_id'])
            ->map(fn (object $row): string => $row->type.'#'.$row->share_lot_id)
            ->flip();

        $new = array_values(array_filter($rows, fn (array $row): bool => ! $existing->has($row['type'].'#'.$row['share_lot_id'])));

        return $this->summarise($month, $plan, $rows, $new);
    }

    public function __invoke(YearMonth $month, ?User $actor = null): DueGenerationPlan
    {
        $this->assertMonthAllowed($month);
        $plan = $this->planFor($month);

        $lock = Cache::lock('dues:'.$month, 600);

        if (! $lock->get()) {
            throw DomainRuleViolation::because('dues.errors.already_running', ['month' => (string) $month]);
        }

        try {
            $all = [];
            $created = [];

            foreach ($this->builder->memberIds($month)->chunk(self::CHUNK) as $chunk) {
                DB::transaction(function () use ($month, $plan, $chunk, &$all, &$created): void {
                    foreach ($this->builder->rows($month, $plan, $chunk->all()) as $row) {
                        $all[] = $row;

                        if (DB::table('dues')->insertOrIgnore($row) === 1) {
                            $created[] = $row;
                        }
                    }
                }, attempts: 3);
            }
        } finally {
            $lock->release();
        }

        $result = $this->summarise($month, $plan, $all, $created);

        activity('dues')
            ->causedBy($actor)
            ->event('dues_generated')
            ->withProperties([
                'month' => (string) $month,
                'rate_plan' => $plan->code,
                'created' => $result->newCount(),
                'amount_poisha' => $result->newTotal()->poisha,
                'already_existing' => $result->alreadyExisting,
            ])
            ->log('dues_generated');

        event(new MonthlyDuesGenerated($result));

        return $result;
    }

    /**
     * No gaps and no far-future months: a month may be generated only up to next month, and
     * only straight after the latest generated one (or any earlier month again).
     */
    public function assertMonthAllowed(YearMonth $month): void
    {
        $limit = YearMonth::current()->next();

        if ($month->isAfter($limit)) {
            throw DomainRuleViolation::because('dues.errors.too_far_ahead', ['month' => (string) $month, 'limit' => (string) $limit]);
        }

        $latest = $this->generated->latest();

        if ($latest !== null && $month->isAfter($latest->next())) {
            throw DomainRuleViolation::because('dues.errors.gap', ['month' => (string) $month, 'next' => (string) $latest->next()]);
        }
    }

    private function planFor(YearMonth $month): RatePlan
    {
        try {
            return $this->rates->for($month);
        } catch (NoRatePlanForMonth) {
            throw DomainRuleViolation::because('members.errors.no_rate_plan', ['month' => (string) $month]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $all
     * @param  list<array<string, mixed>>  $new
     */
    private function summarise(YearMonth $month, RatePlan $plan, array $all, array $new): DueGenerationPlan
    {
        $of = fn (DueType $type): array => array_values(array_filter($new, fn (array $row): bool => $row['type'] === $type->value));
        $sum = fn (array $rows): Money => Money::ofPoisha(array_sum(array_column($rows, 'amount_poisha')));
        $deposits = array_values(array_filter($all, fn (array $row): bool => $row['type'] === DueType::Deposit->value));

        return new DueGenerationPlan(
            month: $month,
            plan: $plan,
            memberCount: count(array_unique(array_column($all, 'member_id'))),
            shareCount: $plan->share_unit_poisha->isPositive()
                ? intdiv(array_sum(array_column($deposits, 'amount_poisha')), $plan->share_unit_poisha->poisha)
                : 0,
            depositCount: count($of(DueType::Deposit)),
            depositTotal: $sum($of(DueType::Deposit)),
            serviceCount: count($of(DueType::ServiceCharge)),
            serviceTotal: $sum($of(DueType::ServiceCharge)),
            alreadyExisting: count($all) - count($new),
        );
    }
}
