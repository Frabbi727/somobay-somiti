<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Events\MonthlyDuesGenerated;
use App\Domain\Contributions\Jobs\GenerateMonthlyDuesJob;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Actions\DeactivateMember;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Services\RateImpactPreviewer;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));
    $this->julyPlan = approvedPlan('2026-07', '500'); // + ৳10 service, due on the 10th
    $this->a = onboard(2, '2026-07');
    $this->b = onboard(1, '2026-08');
    $c = onboard(3, '2026-07');
    app(DeactivateMember::class)(userWithRole(Role::Secretary), $c, 'Paused by committee');
    $this->generate = app(GenerateMonthlyDues::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function monthlyDues(string $month): array
{
    return Due::query()
        ->where('month', YearMonth::parse($month)->toDateString())
        ->whereIn('type', [DueType::Deposit, DueType::ServiceCharge])
        ->orderBy('member_id')->orderBy('type')
        ->get()
        ->map(fn (Due $due): array => [$due->member_id, $due->type->value, $due->amount_poisha->poisha])
        ->all();
}

function generationKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('creates deposit and service dues for active members per share lot', function (): void {
    $july = ($this->generate)(YearMonth::of(2026, 7));

    expect(monthlyDues('2026-07'))->toBe([
        [$this->a->id, 'deposit', 100000],
        [$this->a->id, 'service_charge', 2000],
    ])
        ->and($july->memberCount)->toBe(1)
        ->and($july->shareCount)->toBe(2)
        ->and($july->newTotal()->poisha)->toBe(102000);

    $august = ($this->generate)(YearMonth::of(2026, 8));

    expect($august->memberCount)->toBe(2)
        ->and($august->shareCount)->toBe(3)
        ->and($august->newTotal()->poisha)->toBe(153000);

    $due = Due::query()->where('member_id', $this->a->id)->where('type', DueType::Deposit)->orderBy('id')->first();

    expect($due?->due_date->toDateString())->toBe('2026-07-10')
        ->and($due?->rate_plan_id)->toBe($this->julyPlan->id)
        ->and($due?->snapshot['share_unit_poisha'])->toBe(50000);
});

it('is idempotent: running a month twice gives the same rows', function (): void {
    ($this->generate)(YearMonth::of(2026, 7));
    ($this->generate)(YearMonth::of(2026, 8));
    $count = Due::query()->count();

    $again = ($this->generate)(YearMonth::of(2026, 8));

    expect(Due::query()->count())->toBe($count)
        ->and($again->newCount())->toBe(0)
        ->and($again->alreadyExisting)->toBe(4);
});

it('previews exactly what it then creates', function (): void {
    ($this->generate)(YearMonth::of(2026, 7));
    $preview = $this->generate->preview(YearMonth::of(2026, 8));

    $result = ($this->generate)(YearMonth::of(2026, 8));

    expect($preview->newCount())->toBe($result->newCount())
        ->and($preview->newTotal()->equals($result->newTotal()))->toBeTrue()
        ->and($preview->alreadyExisting)->toBe(0)
        ->and($this->generate->preview(YearMonth::of(2026, 8))->newCount())->toBe(0);
});

it('generates the totals the rate impact preview promised (P2.S2 acceptance)', function (): void {
    foreach (['2026-07', '2026-08', '2026-09', '2026-10'] as $month) {
        ($this->generate)(YearMonth::parse($month));
    }

    $plan = app(DraftRatePlan::class)(userWithRole(Role::Accountant), ratePlanData('2026-11', '600'));
    $preview = app(RateImpactPreviewer::class)->preview($plan);

    app(SubmitRatePlan::class)(User::query()->findOrFail($plan->created_by), $plan);
    app(ApproveRatePlan::class)(userWithRole(Role::President), $plan->fresh());
    app(ApproveRatePlan::class)(userWithRole(Role::Secretary), $plan->fresh());

    $november = ($this->generate)(YearMonth::of(2026, 11));

    expect($november->newTotal()->equals($preview->newMonthlyTotal()))->toBeTrue()
        ->and($november->shareCount)->toBe($preview->shareCount())
        ->and($november->memberCount)->toBe($preview->memberCount())
        ->and(monthlyDues('2026-10')[0][2])->toBe(100000);
});

it('refuses gaps and months too far ahead', function (): void {
    ($this->generate)(YearMonth::of(2026, 7));

    expect(generationKey(fn () => ($this->generate)(YearMonth::of(2026, 9))))->toBe('dues.errors.gap')
        ->and(generationKey(fn () => ($this->generate)(YearMonth::of(2026, 12))))->toBe('dues.errors.too_far_ahead')
        ->and(generationKey(fn () => ($this->generate)(YearMonth::of(2026, 6))))->toBe('members.errors.no_rate_plan');
});

it('keeps two runs of the same month from overlapping', function (): void {
    $lock = Cache::lock('dues:2026-07', 60);
    $lock->get();

    expect(generationKey(fn () => ($this->generate)(YearMonth::of(2026, 7))))->toBe('dues.errors.already_running');

    $lock->release();
});

it('closes generated months to share and rate changes (BR-1, BR-7)', function (): void {
    ($this->generate)(YearMonth::of(2026, 7));
    ($this->generate)(YearMonth::of(2026, 8));

    expect(generationKey(fn () => app(ChangeShares::class)(userWithRole(Role::Secretary), $this->a, 1, YearMonth::of(2026, 8))))->toBe('members.errors.month_generated');

    $plan = app(DraftRatePlan::class)(userWithRole(Role::Accountant), ratePlanData('2026-08', '550'));
    expect(generationKey(fn () => app(SubmitRatePlan::class)(User::query()->findOrFail($plan->created_by), $plan)))->toBe('rates.errors.month_generated');
});

it('uses each lot in its own months after a share change', function (): void {
    ($this->generate)(YearMonth::of(2026, 7));
    app(ChangeShares::class)(userWithRole(Role::Secretary), $this->a, -1, YearMonth::of(2026, 8));

    ($this->generate)(YearMonth::of(2026, 8));

    expect(collect(monthlyDues('2026-08'))->where(0, $this->a->id)->where(1, 'deposit')->sum(2))->toBe(50000);
});

it('announces the result for the advance engine', function (): void {
    Event::fake([MonthlyDuesGenerated::class]);

    ($this->generate)(YearMonth::of(2026, 7));

    Event::assertDispatched(MonthlyDuesGenerated::class, fn (MonthlyDuesGenerated $event): bool => $event->result->newCount() === 2);
});

it('runs from the command line and on the queue with a notification', function (): void {
    $this->artisan('somiti:dues:generate', ['month' => '2026-07'])->assertSuccessful();
    $this->artisan('somiti:dues:generate', ['month' => '2026-09'])->assertFailed();

    $accountant = userWithRole(Role::Accountant);
    GenerateMonthlyDuesJob::dispatchSync('2026-08', $accountant->id);

    expect($accountant->notifications()->count())->toBe(1)
        ->and($accountant->notifications()->first()?->data['title'])->toBe('আগস্ট ২০২৬ মাসের বকেয়া তৈরি হয়েছে: নতুন ৪ টি, ৳ ১,৫৩০.০০।');
});
