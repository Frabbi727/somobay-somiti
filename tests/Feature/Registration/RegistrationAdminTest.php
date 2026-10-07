<?php

declare(strict_types=1);

use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Support\Time\YearMonth;
use Database\Seeders\SmsTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    approvedPlan('2026-07', '500');
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
});

it('invites a member from the members list', function (): void {
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ListMembers::class)
        ->callAction('invite', ['mobile' => '01811-111111', 'password' => 'secret-123', 'password_confirmation' => 'secret-123'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(MemberApplication::query()->sole()->mobile)->toBe('01811111111');
});

it('lists registrations and filters those waiting for me', function (): void {
    $waiting = submittedRegistration('01811111111');
    $draft = invite('01822222222');
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ListMemberApplications::class)
        ->assertCanSeeTableRecords([$waiting, $draft])
        ->filterTable('waiting_for_me')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('takes a registration through both approvals after typing the mobile', function (): void {
    $application = submittedRegistration();
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertSee('Secretary approval')
        ->callAction('approve', ['confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text']);

    expect($application->fresh()?->current_step)->toBe(0);

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', ['confirm_text' => '01811111111'])
        ->assertHasNoActionErrors();

    expect($application->fresh()?->current_step)->toBe(1);

    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertActionVisible('approve')
        ->callAction('approve', ['shares' => 3, 'effective_from' => '2026-07', 'confirm_text' => '01811111111'])
        ->assertHasNoActionErrors();

    expect($application->fresh()?->status)->toBe(MemberApplicationStatus::Approved)
        ->and(Member::query()->sole()->sharesIn(YearMonth::of(2026, 7)))->toBe(3);
});

it('keeps showing every nominee after a decision', function (): void {
    $application = app(SubmitRegistration::class)(completeRegistration(invite(), ['nominees' => [
        nominee(['name' => 'Karima', 'share_percent' => '60']),
        nominee(['name' => 'Rahim', 'relation_id' => relationId('son'), 'nid' => '1234567891', 'share_percent' => '40']),
    ]]), (string) Str::uuid());
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', ['confirm_text' => '01811111111'])
        ->assertHasNoActionErrors()
        ->assertSee('Rahim')
        ->assertSee('President approval pending')
        ->assertDontSee('Secretary approval pending');
});

it('links an approved registration to its member', function (): void {
    $application = approveRegistration(submittedRegistration());
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertSee('Approved')
        ->assertDontSee('You are now a member')
        ->assertActionVisible('openMember')
        ->assertActionHidden('approve');
});

it('starts the final approval in the first month without dues', function (): void {
    onboard();
    generateMonth('2026-07');
    $application = submittedRegistration();
    $this->actingAs(userWithRole(Role::Secretary));
    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', ['confirm_text' => '01811111111']);

    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->mountAction('approve')
        ->assertSchemaStateSet(['effective_from' => '2026-08'], 'mountedActionSchema0')
        ->fillForm(['effective_from' => '2026-07'])
        ->assertMountedActionModalSee(__('members.errors.month_generated', ['month' => '2026-07', 'latest' => '2026-07']))
        ->fillForm(['effective_from' => '2026-08', 'confirm_text' => '01811111111'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($application->fresh()?->status)->toBe(MemberApplicationStatus::Approved);
});

it('hides the decision buttons from the wrong role', function (): void {
    $application = submittedRegistration();
    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('sendBack')
        ->assertActionHidden('reject');
});

it('sends back and rejects with a reason', function (): void {
    $returned = submittedRegistration('01811111111');
    $rejected = app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), (string) Str::uuid());
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMemberApplication::class, ['record' => $returned->getRouteKey()])
        ->callAction('sendBack', ['reason' => 'Photo missing', 'confirm_text' => '01811111111'])
        ->assertHasNoActionErrors();

    Livewire::test(ViewMemberApplication::class, ['record' => $rejected->getRouteKey()])
        ->callAction('reject', ['reason' => 'Lives outside the area', 'confirm_text' => '01822222222'])
        ->assertHasNoActionErrors();

    expect($returned->fresh()?->status)->toBe(MemberApplicationStatus::Returned)
        ->and($rejected->fresh()?->status)->toBe(MemberApplicationStatus::Rejected);
});

it('previews the registration fee on the final approval', function (): void {
    $application = submittedRegistration();
    $this->actingAs(userWithRole(Role::Secretary));
    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->mountAction('approve')
        ->assertMountedActionModalDontSee(__('members.actions.registration_fee_summary', ['amount' => '৳ 300.00']));
    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('approve', ['confirm_text' => '01811111111']);

    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->mountAction('approve')
        ->fillForm(['shares' => 3, 'effective_from' => '2026-07'])
        ->assertMountedActionModalSee(__('members.actions.registration_fee_summary', ['amount' => '৳ 300.00']))
        ->fillForm(['effective_from' => '2026-06'])
        ->assertMountedActionModalSee(__('members.errors.no_rate_plan', ['month' => '2026-06']));
});
