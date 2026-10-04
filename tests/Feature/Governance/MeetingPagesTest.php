<?php

declare(strict_types=1);

use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Enums\Role;
use App\Filament\Resources\Meetings\Pages\CreateMeeting;
use App\Filament\Resources\Meetings\Pages\ListMeetings;
use App\Filament\Resources\Meetings\Pages\ViewMeeting;
use App\Filament\Resources\Meetings\RelationManagers\ResolutionsRelationManager;
use App\Filament\Resources\Resolutions\Pages\ListResolutions;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    travelTo('2026-09-10');
    Filament::setCurrentPanel('admin');
    config(['somiti.quorum_bps.committee' => 5001]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('runs a committee meeting from the screens: schedule, attendance, held, propose, vote', function (): void {
    $secretary = userWithRole(Role::Secretary);
    $president = userWithRole(Role::President);
    $this->actingAs($secretary);

    $this->get(ListMeetings::getUrl())->assertOk();

    Livewire::test(CreateMeeting::class)
        ->fillForm(['type' => 'committee', 'title' => 'September committee meeting', 'scheduled_at' => '2026-09-10 10:00', 'venue' => 'Office'])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    $meeting = Meeting::query()->sole();

    Livewire::test(ViewMeeting::class, ['record' => $meeting->getRouteKey()])
        ->callAction('attendance', data: ['ids' => [$secretary->id, $president->id]])
        ->assertHasNoActionErrors()
        ->callAction('hold', data: ['minutes' => 'Both officers present.'])
        ->assertHasNoActionErrors();

    expect($meeting->fresh()?->status)->toBe(MeetingStatus::Held)
        ->and($meeting->fresh()?->quorum_met)->toBeTrue();

    $relation = fn () => Livewire::test(ResolutionsRelationManager::class, ['ownerRecord' => $meeting->fresh(), 'pageClass' => ViewMeeting::class]);

    $relation()
        ->callTableAction('propose', data: ['subject' => 'advance_policy', 'majority' => 'simple', 'title' => 'Ratify advance policy', 'body' => 'Apply advances at the current rate.'])
        ->assertHasNoTableActionErrors();

    $resolution = Resolution::query()->sole();

    $relation()
        ->callAction(TestAction::make('decide')->table($resolution), data: ['votes_for' => 2, 'votes_against' => 0, 'votes_abstain' => 0, 'confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text']);

    $relation()
        ->callAction(TestAction::make('decide')->table($resolution), data: ['votes_for' => 2, 'votes_against' => 0, 'votes_abstain' => 0, 'confirm_text' => $resolution->resolution_no])
        ->assertHasNoActionErrors();

    expect($resolution->fresh()?->status)->toBe(ResolutionStatus::Passed);

    $this->get(ListResolutions::getUrl())->assertOk()->assertSee($resolution->resolution_no);
});

it('shows meetings to the accountant read-only and hides them from the cashier', function (): void {
    $meeting = app(App\Domain\Governance\Actions\CreateMeeting::class)(userWithRole(Role::Secretary), MeetingData::fromForm([
        'type' => 'committee', 'title' => 'Committee meeting', 'scheduled_at' => '2026-09-10 10:00',
    ]));

    $this->actingAs(userWithRole(Role::Cashier));
    $this->get(ViewMeeting::getUrl(['record' => $meeting]))->assertForbidden();

    $this->actingAs(userWithRole(Role::Accountant));
    $this->get(ViewMeeting::getUrl(['record' => $meeting]))->assertOk();

    Livewire::test(ViewMeeting::class, ['record' => $meeting->getRouteKey()])
        ->assertActionHidden('attendance')
        ->assertActionHidden('hold')
        ->assertActionHidden('cancel');
});
