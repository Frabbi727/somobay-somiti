<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Actions\RefundAdvance;
use App\Domain\Contributions\Actions\RejectPayment;
use App\Domain\Contributions\Actions\ReversePayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
| SOMITI_SPEC.md P7.S1: a randomised year of society life per seed — members joining and changing
| shares, rate changes with either advance policy, late fees, payments (approved, rejected,
| reversed), advance refunds — with every §6.7 invariant asserted after each month.
|
| Seeds: SIMULATION_SEEDS (default 25 in the everyday suite; `composer test:simulation` and CI run
| 1,000), or a single seed with SIMULATION_SEED=<n> to reproduce a failure.
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<int>
 */
function simulationSeeds(): array
{
    $one = getenv('SIMULATION_SEED');

    if (is_string($one) && $one !== '') {
        return [(int) $one];
    }

    $count = getenv('SIMULATION_SEEDS');

    return range(1, is_string($count) && $count !== '' ? max(1, (int) $count) : 25);
}

final class Simulation
{
    /** @var array<string, User> */
    private array $staff = [];

    /** @var list<Member> */
    private array $members = [];

    /** @var array<string, int> */
    public array $done = [];

    public function __construct(private readonly Randomizer $random) {}

    public function run(): void
    {
        $this->travel('2026-07-01');
        app(OpenFiscalYear::class)($this->as(Role::Accountant), 2026);
        $this->plan(YearMonth::of(2026, 7));

        foreach (range(1, $this->random->getInt(2, 5)) as $ignored) {
            $this->join(YearMonth::of(2026, 7));
        }

        foreach (YearMonth::range(YearMonth::of(2026, 7), YearMonth::of(2027, 2)) as $month) {
            $this->month($month);
            $this->assertInvariants((string) $month);
        }
    }

    private function month(YearMonth $month): void
    {
        $this->travel($month->toDateString());

        if (! $month->equals(YearMonth::of(2026, 7)) && $this->chance(30)) {
            $this->join($month);
        }

        generateMonth((string) $month);

        foreach (range(1, $this->random->getInt(2, 8)) as $ignored) {
            $this->travel($month->day($this->random->getInt(2, 27))->toDateString());

            match ($this->random->getInt(1, 10)) {
                1, 2, 3, 4, 5 => $this->pay(),
                6 => $this->payAndReject(),
                7 => $this->reverse(),
                8 => $this->refund(),
                9 => $this->changeShares($month->next()),
                default => $this->lateFees(),
            };
        }

        $this->travel($month->day(28)->toDateString());
        $this->lateFees();

        if ($this->chance(25)) {
            $this->plan($month->next());
        }
    }

    private function plan(YearMonth $month): void
    {
        $lateFees = match ($this->random->getInt(1, 3)) {
            1 => ['late_fee_mode' => 'none'],
            2 => ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofPoisha($this->random->getInt(1, 50) * 100), 'late_fee_frequency' => $this->pick(['once', 'monthly_until_paid'])],
            default => [
                'late_fee_mode' => 'percent', 'late_fee_percent' => (string) $this->random->getInt(1, 5),
                'late_fee_base' => $this->pick(['deposit_only', 'deposit_plus_service', 'outstanding_total']),
                'late_fee_cap_poisha' => Money::ofPoisha($this->random->getInt(10, 100) * 100), 'late_fee_frequency' => $this->pick(['once', 'monthly_until_paid']),
            ],
        };

        $data = ratePlanData((string) $month, (string) ($this->random->getInt(6, 20) * 50), [
            'service_charge_per_share_poisha' => Money::ofPoisha($this->random->getInt(0, 4) * 500),
            'registration_fee_per_share_poisha' => Money::ofPoisha($this->random->getInt(0, 2) * 5000),
            'due_day' => $this->random->getInt(1, 15),
            'grace_days' => $this->random->getInt(0, 10),
            'advance_policy' => $this->pick(['apply_at_current_rate', 'lock_prepaid_months']),
            ...$lateFees,
        ]);

        $plan = app(DraftRatePlan::class)($this->as(Role::Accountant), $data);
        app(SubmitRatePlan::class)($this->as(Role::Accountant), $plan);
        app(ApproveRatePlan::class)($this->as(Role::President), $plan);
        app(ApproveRatePlan::class)($this->as(Role::Secretary), $plan);
        $this->count('rate plans');
    }

    private function join(YearMonth $from): void
    {
        $this->members[] = onboard($this->random->getInt(1, 3), (string) $from, ['joined_on' => $from->toDateString()]);
        $this->count('members');
    }

    private function record(): Payment
    {
        $method = $this->pick(['cash', 'cash', 'bkash', 'bank']);

        return app(RecordPayment::class)($this->as(Role::Cashier), PaymentData::fromForm([
            'member_id' => $this->member()->id,
            'method' => $method,
            'amount' => Money::ofPoisha($this->random->getInt(1, 60) * 5000 + $this->random->getInt(0, 1) * $this->random->getInt(1, 99)),
            'received_on' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'trx_id' => $method === 'cash' ? null : 'TRX'.bin2hex($this->random->getBytes(5)),
        ]));
    }

    private function pay(): void
    {
        $this->attempt('payments', fn () => app(ApprovePayment::class)($this->as(Role::Accountant), $this->record()));
    }

    private function payAndReject(): void
    {
        $this->attempt('rejections', fn () => app(RejectPayment::class)($this->as(Role::Accountant), $this->record(), 'Amount does not match the slip'));
    }

    private function reverse(): void
    {
        $payment = Payment::query()->where('status', PaymentStatus::Approved)->inRandomOrder($this->random->getInt(1, PHP_INT_MAX))->first();

        if ($payment !== null) {
            $this->attempt('reversals', fn () => app(ReversePayment::class)($this->as(Role::Accountant), $payment, 'Cheque bounced, simulated'));
        }
    }

    private function refund(): void
    {
        $member = $this->member();
        $balance = app(AdvanceLedger::class)->balance($member->id);

        if ($balance->isPositive()) {
            $amount = Money::ofPoisha($this->random->getInt(1, $balance->poisha));
            $this->attempt('refunds', fn () => app(RefundAdvance::class)($this->as(Role::President), $member, $amount, PaymentMethod::Cash, 'Member asked for the advance back'));
        }
    }

    private function changeShares(YearMonth $from): void
    {
        $delta = $this->pick([-2, -1, 1, 2]);
        $this->attempt('share changes', fn () => app(ChangeShares::class)($this->as(Role::Secretary), $this->member(), $delta, $from, 'simulated'));
    }

    private function lateFees(): void
    {
        app(ApplyLateFees::class)(CarbonImmutable::now('Asia/Dhaka'));
        $this->count('late fee runs');
    }

    private function assertInvariants(string $month): void
    {
        $findings = app(InvariantChecker::class)->findings();

        if ($findings !== []) {
            throw new RuntimeException("Invariants broken after {$month}:\n- ".implode("\n- ", $findings));
        }
    }

    /**
     * Runs a step that the business rules may legitimately refuse (e.g. reversing into a closed
     * advance, decreasing below one share); a refusal must leave the books untouched.
     */
    private function attempt(string $what, Closure $step): void
    {
        try {
            $step();
            $this->count($what);
        } catch (DomainRuleViolation) {
            $this->count($what.' refused');
        }
    }

    private function as(Role $role): User
    {
        return $this->staff[$role->value] ??= userWithRole($role);
    }

    private function member(): Member
    {
        return $this->members[$this->random->getInt(0, count($this->members) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $options
     * @return T
     */
    private function pick(array $options): mixed
    {
        return $options[$this->random->getInt(0, count($options) - 1)];
    }

    private function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    private function travel(string $date): void
    {
        travelTo($date);
    }

    private function count(string $what): void
    {
        $this->done[$what] = ($this->done[$what] ?? 0) + 1;
    }
}

it('keeps every invariant through a randomised year', function (int $seed): void {
    Notification::fake();
    Queue::fake();
    $this->seed(ChartOfAccountsSeeder::class);

    $simulation = new Simulation(new Randomizer(new Mt19937($seed)));

    try {
        $simulation->run();
    } catch (Throwable $exception) {
        throw new RuntimeException(
            "Simulation seed {$seed} failed — reproduce with: SIMULATION_SEED={$seed} php artisan test tests/Invariants/SimulationTest.php\n".$exception->getMessage(),
            previous: $exception,
        );
    }

    expect($simulation->done['payments'] ?? 0)->toBeGreaterThan(0);
})->with(simulationSeeds())->group('simulation');
