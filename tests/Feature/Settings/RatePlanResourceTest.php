<?php

declare(strict_types=1);

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\CreateRatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\EditRatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\ListRatePlans;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\ViewRatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    $this->secretary = userWithRole(Role::Secretary);
});

function rateFormAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', 'content');
}

function pendingPlan(User $author, string $month = '2026-11'): RatePlan
{
    $plan = app(DraftRatePlan::class)($author, ratePlanData($month, '600'));

    return app(SubmitRatePlan::class)($author, $plan);
}

it('drafts a plan with a percentage late fee from the form', function (): void {
    $this->actingAs($this->accountant);
    $undo = Repeater::fake();

    Livewire::test(CreateRatePlan::class)
        ->fillForm([
            'effective_from' => '2026-11',
            'share_unit_poisha' => '600',
            'service_charge_per_share_poisha' => '10',
            'registration_fee_per_share_poisha' => '১০০',
            'due_day' => 10,
            'grace_days' => 5,
            'late_fee_mode' => 'percent',
            'late_fee_percent' => '2',
            'late_fee_base' => 'deposit_only',
            'late_fee_cap_poisha' => '50',
            'late_fee_frequency' => 'once',
            'advance_policy' => 'apply_at_current_rate',
            'registration_fee_on_rate_increase' => 'none',
            'allocation_order' => [['type' => 'late_fee'], ['type' => 'service_charge'], ['type' => 'registration'], ['type' => 'deposit']],
        ])
        ->callAction(rateFormAction('create'))
        ->assertHasNoFormErrors()
        ->assertNotified(__('rates.notifications.created', ['code' => 'RP-2026-11-v1']));

    $undo();

    $plan = RatePlan::query()->sole();

    expect($plan->share_unit_poisha->poisha)->toBe(60000)
        ->and($plan->registration_fee_per_share_poisha->poisha)->toBe(10000)
        ->and($plan->late_fee_bps)->toBe(200)
        ->and($plan->late_fee_cap_poisha?->poisha)->toBe(5000)
        ->and($plan->status)->toBe(RatePlanStatus::Draft);
});

it('asks for the late fee details that the chosen type needs', function (): void {
    $this->actingAs($this->accountant);

    Livewire::test(CreateRatePlan::class)
        ->fillForm(['effective_from' => '2026-11', 'share_unit_poisha' => '600', 'late_fee_mode' => 'fixed'])
        ->mountAction(rateFormAction('create'))
        ->assertHasFormErrors(['late_fee_fixed_poisha' => 'required', 'late_fee_frequency' => 'required']);
});

it('loads a draft for editing with the percentage shown as text', function (): void {
    $this->actingAs($this->accountant);
    $plan = app(DraftRatePlan::class)($this->accountant, ratePlanData('2026-11', '600', [
        'late_fee_mode' => 'percent', 'late_fee_percent' => '2.5', 'late_fee_base' => 'deposit_only', 'late_fee_frequency' => 'once',
    ]));

    Livewire::test(EditRatePlan::class, ['record' => $plan->getRouteKey()])
        ->assertSchemaStateSet([
            'effective_from' => '2026-11',
            'share_unit_poisha' => '600.00',
            'late_fee_percent' => '2.50',
        ])
        ->fillForm(['share_unit_poisha' => '650'])
        ->callAction(rateFormAction('save'))
        ->assertHasNoFormErrors();

    expect($plan->fresh()?->share_unit_poisha->poisha)->toBe(65000);
});

it('shows approved plans as view-only with duplicate as the way forward', function (): void {
    $plan = approvedPlan('2026-07');
    $this->actingAs($this->accountant);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('submit')
        ->assertActionHidden('delete')
        ->assertActionVisible('duplicate');

    $this->get(RatePlanResource::getUrl('edit', ['record' => $plan]))->assertForbidden();
});

it('approves with the plan code typed, needing both committee members', function (): void {
    $plan = pendingPlan($this->accountant);

    $this->actingAs($this->president);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => 'RP-2026-11'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => 'RP-2026-11-v1', 'comment' => 'As decided in the meeting'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('rates.notifications.approval_recorded', ['code' => 'RP-2026-11-v1']));

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->assertActionHidden('approve');

    $this->actingAs($this->secretary);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => 'RP-2026-11-v1'])
        ->assertNotified(__('rates.notifications.approved', ['code' => 'RP-2026-11-v1', 'month' => 'November 2026']));

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Approved);
});

it('hides approval from the author and non-committee staff', function (Role $role): void {
    $plan = pendingPlan($this->accountant);
    $this->actingAs($role === Role::Accountant ? $this->accountant : userWithRole($role));

    Livewire::test(ListRatePlans::class)
        ->assertActionHidden(TestAction::make('approve')->table($plan));
})->with([Role::Accountant, Role::Cashier, Role::Auditor, Role::SuperAdmin]);

it('sends a plan back with a reason', function (): void {
    $plan = pendingPlan($this->accountant);
    $this->actingAs($this->secretary);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('reject', data: ['comment' => ''])
        ->assertHasActionErrors(['comment']);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('reject', data: ['comment' => 'Service charge should stay 10'])
        ->assertHasNoActionErrors();

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Draft);
});

it('cancels an approved plan with the code and a reason', function (): void {
    $plan = approvedPlan('2026-07');
    $this->actingAs($this->president);

    Livewire::test(ListRatePlans::class)
        ->callAction(TestAction::make('cancel')->table($plan), data: ['reason' => 'Decision withdrawn', 'confirm_text' => 'RP-2026-07-v1'])
        ->assertHasNoActionErrors();

    expect($plan->fresh()?->status)->toBe(RatePlanStatus::Cancelled);
});

it('duplicates an approved plan into an editable draft', function (): void {
    $plan = approvedPlan('2026-07');
    $this->actingAs($this->accountant);

    Livewire::test(ViewRatePlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('duplicate')
        ->assertRedirect();

    expect(RatePlan::query()->where('status', RatePlanStatus::Draft)->count())->toBe(1);
});

it('counts plans awaiting approval on the menu badge', function (): void {
    pendingPlan($this->accountant, '2026-11');
    pendingPlan($this->accountant, '2026-12');

    expect(RatePlanResource::getNavigationBadge())->toBe('2');
});

it('renders the rate timeline in Bangla', function (): void {
    approvedPlan('2026-07');
    $this->actingAs($this->president);
    app()->setLocale('bn');

    $this->get(RatePlanResource::getUrl('index'))
        ->assertOk()
        ->assertSee('হার ও পরিকল্পনা')
        ->assertSee('জুলাই ২০২৬')
        ->assertSee('৳ ৫০০.০০')
        ->assertSee('অনুমোদিত');
});

it('opens the create page with sensible defaults', function (): void {
    $this->actingAs($this->accountant);
    $undo = Repeater::fake();

    Livewire::test(CreateRatePlan::class)
        ->assertSchemaStateSet([
            'due_day' => 10,
            'grace_days' => 5,
            'late_fee_mode' => LateFeeMode::None,
            'allocation_order.0.type' => DueType::LateFee,
            'allocation_order.3.type' => DueType::Deposit,
        ]);

    $undo();

    $this->get(RatePlanResource::getUrl('create'))->assertOk();
});
