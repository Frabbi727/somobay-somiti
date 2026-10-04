<?php

declare(strict_types=1);

use App\Domain\Governance\Actions\CreateMeeting;
use App\Domain\Governance\Actions\DecideResolution;
use App\Domain\Governance\Actions\HoldMeeting;
use App\Domain\Governance\Actions\ProposeResolution;
use App\Domain\Governance\Actions\RecordAttendance;
use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Data\ResolutionData;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\LinkRatePlanResolution;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\ViewRatePlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Phase 9 (P9.S2): "linked resolution required where configured" — rate plans first.
*/

beforeEach(function (): void {
    travelTo('2026-09-10');
    config(['somiti.require_resolution_for' => ['rate_plan'], 'somiti.quorum_bps.committee' => 1]);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    $this->secretary = userWithRole(Role::Secretary);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pendingPlanFor(User $author): RatePlan
{
    $plan = app(DraftRatePlan::class)($author, ratePlanData('2026-11', '600'));

    return app(SubmitRatePlan::class)($author, $plan);
}

/**
 * A committee meeting that votes on one resolution; returns it decided.
 */
function committeeResolution(string $subject = 'rate_plan', int $for = 1, int $against = 0): Resolution
{
    $secretary = User::query()->role('secretary')->firstOrFail();
    $meeting = app(CreateMeeting::class)($secretary, MeetingData::fromForm([
        'type' => 'committee', 'title' => 'Committee meeting', 'scheduled_at' => '2026-09-10 10:00',
    ]));
    $resolution = app(ProposeResolution::class)($secretary, $meeting, ResolutionData::fromForm([
        'subject' => $subject, 'title' => 'Adopt ৳600 from November', 'body' => 'Share unit becomes ৳600 from November 2026.',
    ]));

    app(RecordAttendance::class)($secretary, $meeting, [$secretary->id]);
    app(HoldMeeting::class)($secretary, $meeting);

    return app(DecideResolution::class)($secretary, $resolution, $for, $against, 0);
}

function requiredRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('refuses to approve a plan without a passed resolution when one is required', function (): void {
    $plan = pendingPlanFor($this->accountant);

    expect(requiredRule(fn () => app(ApproveRatePlan::class)($this->president, $plan)))->toBe('governance.errors.resolution_required');

    app(LinkRatePlanResolution::class)($this->accountant, $plan, committeeResolution());
    app(ApproveRatePlan::class)($this->president, $plan);
    app(ApproveRatePlan::class)($this->secretary, $plan);

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Approved);
});

it('approves without a resolution when none is configured', function (): void {
    config(['somiti.require_resolution_for' => []]);
    $plan = pendingPlanFor($this->accountant);

    app(ApproveRatePlan::class)($this->president, $plan);
    app(ApproveRatePlan::class)($this->secretary, $plan);

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Approved);
});

it('only links a passed rate-plan resolution, once', function (): void {
    $plan = pendingPlanFor($this->accountant);

    expect(requiredRule(fn () => app(LinkRatePlanResolution::class)($this->accountant, $plan, committeeResolution(for: 0, against: 1))))->toBe('governance.errors.resolution_unusable')
        ->and(requiredRule(fn () => app(LinkRatePlanResolution::class)($this->accountant, $plan, committeeResolution('bylaws'))))->toBe('governance.errors.resolution_unusable');

    $resolution = committeeResolution();
    app(LinkRatePlanResolution::class)($this->accountant, $plan, $resolution);

    $other = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-12', '650'));

    expect(requiredRule(fn () => app(LinkRatePlanResolution::class)($this->accountant, $other, $resolution)))->toBe('governance.errors.resolution_used');
});

it('freezes the link once the plan is approved', function (): void {
    $plan = pendingPlanFor($this->accountant);
    app(LinkRatePlanResolution::class)($this->accountant, $plan, committeeResolution());
    app(ApproveRatePlan::class)($this->president, $plan);
    app(ApproveRatePlan::class)($this->secretary, $plan);

    expect(fn () => app(LinkRatePlanResolution::class)($this->accountant, $plan, committeeResolution()))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::table('rate_plans')->where('id', $plan->id)->update(['resolution_id' => null]))->toThrow(QueryException::class);
});

it('links a resolution from the rate plan screen', function (): void {
    Filament::setCurrentPanel('admin');
    $plan = pendingPlanFor($this->accountant);
    $resolution = committeeResolution();
    $this->actingAs($this->accountant);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('linkResolution', data: ['resolution_id' => $resolution->id])
        ->assertHasNoActionErrors();

    expect($plan->fresh()?->resolution_id)->toBe($resolution->id);

    $this->get(ViewRatePlan::getUrl(['record' => $plan]))->assertOk()->assertSee($resolution->resolution_no);
});
