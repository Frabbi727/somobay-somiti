<?php

declare(strict_types=1);

use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\CancelRatePlan;
use App\Domain\Settings\Actions\DeleteRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\DuplicateRatePlan;
use App\Domain\Settings\Actions\RejectRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Actions\UpdateRatePlan;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Settings\Contracts\RatePlanUsage;
use App\Domain\Settings\Enums\LateFeeBase;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    $this->secretary = userWithRole(Role::Secretary);
});

function ruleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('drafts versioned plans per effective month', function (): void {
    $draft = app(DraftRatePlan::class);

    $first = $draft($this->accountant, ratePlanData('2026-07'));
    $second = $draft($this->accountant, ratePlanData('2026-07', '550'));
    $other = $draft($this->accountant, ratePlanData('2027-01', '600'));

    expect([$first->code, $second->code, $other->code])->toBe(['RP-2026-07-v1', 'RP-2026-07-v2', 'RP-2027-01-v1'])
        ->and($first->status)->toBe(RatePlanStatus::Draft)
        ->and($first->share_unit_poisha->poisha)->toBe(50000)
        ->and((string) $first->effective_from)->toBe('2026-07');
});

it('validates rates and late fee settings', function (array $overrides, string $key): void {
    expect(ruleKey(fn () => app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-07', overrides: $overrides))))->toBe($key);
})->with([
    'zero share unit' => [['share_unit_poisha' => Money::zero()], 'rates.errors.share_unit_positive'],
    'due day 29' => [['due_day' => 29], 'rates.errors.due_day'],
    'due day 0' => [['due_day' => 0], 'rates.errors.due_day'],
    'too many grace days' => [['grace_days' => 61], 'rates.errors.grace_days'],
    'fixed late fee without amount' => [['late_fee_mode' => 'fixed', 'late_fee_frequency' => 'once'], 'rates.errors.late_fee_incomplete'],
    'percent without base' => [['late_fee_mode' => 'percent', 'late_fee_percent' => '2', 'late_fee_frequency' => 'once'], 'rates.errors.late_fee_incomplete'],
    'percent without frequency' => [['late_fee_mode' => 'percent', 'late_fee_percent' => '2', 'late_fee_base' => 'deposit_only'], 'rates.errors.late_fee_incomplete'],
    'incomplete allocation order' => [['allocation_order' => ['deposit', 'late_fee']], 'rates.errors.allocation_order'],
]);

it('stores a percentage late fee in basis points with an optional cap', function (): void {
    $plan = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-07', overrides: [
        'late_fee_mode' => 'percent',
        'late_fee_percent' => '2.5',
        'late_fee_base' => 'deposit_plus_service',
        'late_fee_cap_poisha' => Money::ofTaka('50'),
        'late_fee_frequency' => 'monthly_until_paid',
        // A stale fixed amount from the form is dropped for percent mode.
        'late_fee_fixed_poisha' => Money::ofTaka('20'),
    ]));

    expect($plan->late_fee_mode)->toBe(LateFeeMode::Percent)
        ->and($plan->late_fee_bps)->toBe(250)
        ->and($plan->late_fee_base)->toBe(LateFeeBase::DepositPlusService)
        ->and($plan->late_fee_cap_poisha?->poisha)->toBe(5000)
        ->and($plan->late_fee_fixed_poisha)->toBeNull();
});

it('needs both the president and the secretary to approve', function (): void {
    $plan = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-07'));
    app(SubmitRatePlan::class)($this->accountant, $plan);

    app(ApproveRatePlan::class)($this->president, $plan);
    expect($plan->fresh()?->status)->toBe(RatePlanStatus::PendingApproval);

    app(ApproveRatePlan::class)($this->secretary, $plan);
    $plan->refresh();

    expect($plan->status)->toBe(RatePlanStatus::Approved)
        ->and($plan->approved_at)->not->toBeNull()
        ->and(array_map(fn (Role $role): string => $role->value, $plan->approvedRoles()))->toEqualCanonicalizing(['president', 'secretary']);
});

it('does not count two approvals from the same role', function (): void {
    $plan = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-07'));
    app(SubmitRatePlan::class)($this->accountant, $plan);

    app(ApproveRatePlan::class)($this->president, $plan);
    app(ApproveRatePlan::class)(userWithRole(Role::President), $plan);

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::PendingApproval);
});

it('keeps the author, repeat voters and other roles from approving', function (Closure $approver): void {
    $plan = app(DraftRatePlan::class)($this->secretary, ratePlanData('2026-07'));
    app(SubmitRatePlan::class)($this->secretary, $plan);
    app(ApproveRatePlan::class)($this->president, $plan);

    app(ApproveRatePlan::class)($approver($this), $plan->fresh());
})->throws(AuthorizationException::class)->with([
    'author' => [fn ($test) => $test->secretary],
    'same president twice' => [fn ($test) => $test->president],
    'cashier' => [fn () => userWithRole(Role::Cashier)],
    'accountant' => [fn () => userWithRole(Role::Accountant)],
]);

it('sends a rejected plan back to draft and ignores earlier approvals on resubmission', function (): void {
    $plan = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-07'));
    app(SubmitRatePlan::class)($this->accountant, $plan);
    app(ApproveRatePlan::class)($this->president, $plan);
    app(RejectRatePlan::class)($this->secretary, $plan, 'Deposit should be 550');

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Draft);

    $plan = app(UpdateRatePlan::class)($this->accountant, $plan->fresh(), ratePlanData('2026-07', '550'));
    app(SubmitRatePlan::class)($this->accountant, $plan);
    app(ApproveRatePlan::class)($this->secretary, $plan->fresh());

    expect($plan->fresh()?->submission_no)->toBe(2)
        ->and($plan->fresh()?->status)->toBe(RatePlanStatus::PendingApproval);

    app(ApproveRatePlan::class)($this->president, $plan->fresh());

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Approved)
        ->and($plan->approvals()->count())->toBe(4);
});

it('never lets an approved plan change', function (): void {
    $plan = approvedPlan('2026-07');

    expect(fn () => $plan->forceFill(['share_unit_poisha' => Money::ofTaka('600')])->save())->toThrow(ImmutableRecord::class)
        ->and(fn () => $plan->fresh()?->delete())->toThrow(ImmutableRecord::class)
        ->and(fn () => app(UpdateRatePlan::class)($this->accountant, $plan, ratePlanData('2026-07', '600')))->toThrow(AuthorizationException::class)
        ->and($plan->fresh()?->share_unit_poisha->poisha)->toBe(50000);
});

it('blocks raw SQL changes to an approved plan with a trigger', function (string $sql): void {
    $plan = approvedPlan('2026-07');

    DB::statement($sql, [$plan->id]);
})->throws(QueryException::class)->with([
    'rate change' => ['UPDATE rate_plans SET share_unit_poisha = 60000 WHERE id = ?'],
    'back to draft' => ["UPDATE rate_plans SET status = 'draft' WHERE id = ?"],
    'delete' => ['DELETE FROM rate_plans WHERE id = ?'],
]);

it('allows only one approved plan per month in the database', function (): void {
    $plan = approvedPlan('2026-07');

    DB::table('rate_plans')->insert([
        ...collect(DB::table('rate_plans')->where('id', $plan->id)->first())->except(['id', 'code'])->all(),
        'code' => 'RP-RAW',
    ]);
})->throws(QueryException::class, 'rate_plans_one_approved_per_month');

it('supersedes an unused approved plan for the same month', function (): void {
    $original = approvedPlan('2026-07');
    $correction = approvedPlan('2026-07', '520');

    expect($original->fresh()?->status)->toBe(RatePlanStatus::Superseded)
        ->and($correction->supersedes_id)->toBe($original->id)
        ->and(app(RateResolver::class)->for(YearMonth::of(2026, 7))->id)->toBe($correction->id);
});

it('resolves the plan for each month', function (string $month, ?string $expectedUnit): void {
    approvedPlan('2026-07', '500');
    approvedPlan('2027-01', '600');
    app(DraftRatePlan::class)($this->accountant, ratePlanData('2027-03', '700'));
    $cancelled = approvedPlan('2027-05', '800');
    app(CancelRatePlan::class)($this->president, $cancelled, 'Committee withdrew it');

    $resolve = fn () => app(RateResolver::class)->for(YearMonth::parse($month));

    if ($expectedUnit === null) {
        expect($resolve)->toThrow(NoRatePlanForMonth::class);

        return;
    }

    expect($resolve()->share_unit_poisha->equals(Money::ofTaka($expectedUnit)))->toBeTrue();
})->with([
    'before any plan' => ['2026-06', null],
    'first month' => ['2026-07', '500'],
    'december' => ['2026-12', '500'],
    'rate change month' => ['2027-01', '600'],
    'draft ignored' => ['2027-03', '600'],
    'cancelled ignored' => ['2027-05', '600'],
    'far future' => ['2030-01', '600'],
]);

it('rejects a plan for an already generated month unless it is a retroactive correction (BR-7)', function (): void {
    app()->instance(GeneratedMonths::class, new class implements GeneratedMonths
    {
        public function latest(): ?YearMonth
        {
            return YearMonth::of(2026, 9);
        }
    });

    $late = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-08', '600'));
    expect(ruleKey(fn () => app(SubmitRatePlan::class)($this->accountant, $late)))->toBe('rates.errors.month_generated');

    $retro = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-08', '600', ['is_retroactive' => true]));
    app(SubmitRatePlan::class)($this->accountant, $retro);
    app(ApproveRatePlan::class)($this->president, $retro);

    expect($retro->fresh()?->status)->toBe(RatePlanStatus::PendingApproval);

    app(ApproveRatePlan::class)($this->secretary, $retro->fresh());

    expect($retro->fresh()?->status)->toBe(RatePlanStatus::Approved);

    $future = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-10', '600'));
    expect(app(SubmitRatePlan::class)($this->accountant, $future)->status)->toBe(RatePlanStatus::PendingApproval);
});

it('cancels an approved plan only while no due uses it (BR-6)', function (): void {
    $plan = approvedPlan('2026-07');

    app()->instance(RatePlanUsage::class, new class implements RatePlanUsage
    {
        public function isReferenced(RatePlan $plan): bool
        {
            return true;
        }
    });

    expect(ruleKey(fn () => app(CancelRatePlan::class)($this->president, $plan, 'Wrong amount')))->toBe('rates.errors.month_in_use')
        ->and(ruleKey(fn () => approvedPlan('2026-07', '520')))->toBe('rates.errors.month_in_use')
        ->and($plan->fresh()?->status)->toBe(RatePlanStatus::Approved);
});

it('cancels with a reason and lets only the president do it', function (): void {
    $plan = approvedPlan('2026-07');

    expect(fn () => app(CancelRatePlan::class)($this->secretary, $plan, 'Not allowed'))->toThrow(AuthorizationException::class)
        ->and(ruleKey(fn () => app(CancelRatePlan::class)($this->president, $plan, 'no')))->toBe('rates.errors.comment_required');

    $cancelled = app(CancelRatePlan::class)($this->president, $plan, 'AGM reversed the decision');

    expect($cancelled->status)->toBe(RatePlanStatus::Cancelled)
        ->and($cancelled->cancelled_reason)->toBe('AGM reversed the decision')
        ->and(fn () => $cancelled->forceFill(['notes' => 'x'])->save())->toThrow(ImmutableRecord::class);
});

it('duplicates a plan as a new draft version', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03', 'Asia/Dhaka'));
    $plan = approvedPlan('2026-07', '500', ['late_fee_mode' => 'fixed', 'late_fee_fixed_poisha' => Money::ofTaka('20'), 'late_fee_frequency' => 'once']);

    $copy = app(DuplicateRatePlan::class)($this->accountant, $plan);
    CarbonImmutable::setTestNow();

    expect($copy->status)->toBe(RatePlanStatus::Draft)
        ->and($copy->code)->toBe('RP-2026-11-v1')
        ->and($copy->share_unit_poisha->poisha)->toBe(50000)
        ->and($copy->late_fee_fixed_poisha?->poisha)->toBe(2000);
});

it('deletes a never-submitted draft but not a submitted one', function (): void {
    $draft = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-07'));
    app(DeleteRatePlan::class)($this->accountant, $draft);

    expect(RatePlan::query()->count())->toBe(0);

    $plan = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-08'));
    app(SubmitRatePlan::class)($this->accountant, $plan);
    app(RejectRatePlan::class)($this->president, $plan, 'Needs late fee');

    expect(fn () => app(DeleteRatePlan::class)($this->accountant, $plan->fresh()))->toThrow(AuthorizationException::class);
});

it('copies its rates into a due snapshot', function (): void {
    $plan = approvedPlan('2026-07');

    expect($plan->snapshot())->toMatchArray([
        'rate_plan_id' => $plan->id,
        'rate_plan_code' => 'RP-2026-07-v1',
        'share_unit_poisha' => 50000,
        'service_charge_per_share_poisha' => 1000,
        'registration_fee_per_share_poisha' => 10000,
        'due_day' => 10,
        'grace_days' => 5,
        'late_fee_mode' => 'none',
        'allocation_order' => ['late_fee', 'service_charge', 'registration', 'deposit'],
    ]);
});
