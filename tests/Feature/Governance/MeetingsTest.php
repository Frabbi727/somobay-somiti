<?php

declare(strict_types=1);

use App\Domain\Governance\Actions\CancelMeeting;
use App\Domain\Governance\Actions\CreateMeeting;
use App\Domain\Governance\Actions\DecideResolution;
use App\Domain\Governance\Actions\HoldMeeting;
use App\Domain\Governance\Actions\ProposeResolution;
use App\Domain\Governance\Actions\RecordAttendance;
use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Data\ResolutionData;
use App\Domain\Governance\Enums\Majority;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\MeetingType;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Phase 9 (P9.S1): meetings, attendance, quorum and resolutions.
*/

beforeEach(function (): void {
    travelTo('2026-09-10');
    $this->seed(ChartOfAccountsSeeder::class);
    approvedPlan('2026-07', '500');
    $this->secretary = userWithRole(Role::Secretary);
    config(['somiti.quorum_bps' => ['committee' => 5001, 'general' => 3334, 'special_general' => 5000]]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function meeting(MeetingType $type = MeetingType::General, string $at = '2026-09-10 16:00'): Meeting
{
    return app(CreateMeeting::class)(userWithRole(Role::Secretary), MeetingData::fromForm([
        'type' => $type, 'title' => 'Annual general meeting 2026', 'scheduled_at' => $at, 'venue' => 'Somiti office',
    ]));
}

function governanceRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('schedules a meeting with a number', function (): void {
    $meeting = meeting();

    expect($meeting->meeting_no)->toMatch('/^MT-\d{4}$/')
        ->and($meeting->status)->toBe(MeetingStatus::Scheduled);
});

it('snapshots quorum when a general meeting is held (one third of active members, rounded up)', function (): void {
    $members = collect(range(1, 7))->map(fn (): int => onboard(1, '2026-07')->id)->all();
    $meeting = meeting();

    app(RecordAttendance::class)($this->secretary, $meeting, array_slice($members, 0, 3));
    $held = app(HoldMeeting::class)($this->secretary, $meeting, 'Accounts for 2025-26 presented.');

    expect($held->status)->toBe(MeetingStatus::Held)
        ->and($held->eligible_count)->toBe(7)
        ->and($held->quorum_required)->toBe(3) // 7 × 33.34% = 2.33 → 3
        ->and($held->attendees_count)->toBe(3)
        ->and($held->quorum_met)->toBeTrue();

    // Later joiners never change a past quorum.
    onboard(1, '2026-09');
    expect($held->fresh()?->eligible_count)->toBe(7);
});

it('counts the managing committee for committee meetings', function (): void {
    $committee = [userWithRole(Role::President)->id, $this->secretary->id, userWithRole(Role::Accountant)->id, userWithRole(Role::Cashier)->id];
    userWithRole(Role::Auditor); // not on the committee

    $meeting = meeting(MeetingType::Committee);

    expect(governanceRule(fn () => app(RecordAttendance::class)($this->secretary, $meeting, [userWithRole(Role::Auditor)->id])))->toBe('governance.errors.not_eligible');

    app(RecordAttendance::class)($this->secretary, $meeting, array_slice($committee, 0, 2));
    $held = app(HoldMeeting::class)($this->secretary, $meeting);

    // Every active president, secretary, accountant and cashier (the test helpers create several); auditors excluded.
    $committeeSize = User::query()->role(['president', 'secretary', 'accountant', 'cashier'])->count();

    expect($held->eligible_count)->toBe($committeeSize)
        ->and($held->quorum_required)->toBe(intdiv($committeeSize * 5001 + 9999, 10000))
        ->and($held->quorum_met)->toBeFalse();
});

it('decides resolutions only at a held meeting with quorum, by the chosen majority', function (): void {
    $members = collect(range(1, 6))->map(fn (): int => onboard(1, '2026-07')->id)->all();
    $meeting = meeting();
    $simple = app(ProposeResolution::class)($this->secretary, $meeting, ResolutionData::fromForm([
        'subject' => 'advance_policy', 'title' => 'Ratify advance policy', 'body' => 'Advances apply at the current rate.',
    ]));
    $special = app(ProposeResolution::class)($this->secretary, $meeting, ResolutionData::fromForm([
        'subject' => 'bylaws', 'title' => 'Amend bylaw 7', 'body' => 'Raise the share limit.', 'majority' => 'two_thirds',
    ]));

    expect(governanceRule(fn () => app(DecideResolution::class)($this->secretary, $simple, 3, 1, 0)))->toBe('governance.errors.meeting_not_held');

    app(RecordAttendance::class)($this->secretary, $meeting, array_slice($members, 0, 5));
    app(HoldMeeting::class)($this->secretary, $meeting);

    expect(governanceRule(fn () => app(DecideResolution::class)($this->secretary, $simple, 5, 1, 0)))->toBe('governance.errors.votes');

    expect(app(DecideResolution::class)($this->secretary, $simple, 3, 2, 0)->status)->toBe(ResolutionStatus::Passed)
        ->and(app(DecideResolution::class)($this->secretary, $special, 3, 2, 0)->status)->toBe(ResolutionStatus::Rejected);
});

it('cannot decide anything at a meeting that missed quorum', function (): void {
    $members = collect(range(1, 9))->map(fn (): int => onboard(1, '2026-07')->id)->all();
    $meeting = meeting();
    $resolution = app(ProposeResolution::class)($this->secretary, $meeting, ResolutionData::fromForm([
        'subject' => 'other', 'title' => 'Picnic', 'body' => 'Hold a picnic in December.',
    ]));

    app(RecordAttendance::class)($this->secretary, $meeting, array_slice($members, 0, 2));
    app(HoldMeeting::class)($this->secretary, $meeting);

    expect(governanceRule(fn () => app(DecideResolution::class)($this->secretary, $resolution, 2, 0, 0)))->toBe('governance.errors.no_quorum');
});

it('works out majorities with abstentions left out', function (Majority $majority, int $for, int $against, bool $passes): void {
    expect($majority->passes($for, $against))->toBe($passes);
})->with([
    [Majority::Simple, 3, 2, true],
    [Majority::Simple, 2, 2, false],
    [Majority::Simple, 0, 0, false],
    [Majority::TwoThirds, 4, 2, true],
    [Majority::TwoThirds, 5, 3, false],
]);

it('does not close a meeting as held before its date', function (): void {
    $meeting = meeting(at: '2026-09-20 16:00');

    expect(governanceRule(fn () => app(HoldMeeting::class)($this->secretary, $meeting)))->toBe('governance.errors.not_yet');
});

it('withdraws open proposals when a meeting is cancelled, and keeps held meetings fixed', function (): void {
    $meeting = meeting();
    $resolution = app(ProposeResolution::class)($this->secretary, $meeting, ResolutionData::fromForm([
        'subject' => 'other', 'title' => 'Picnic', 'body' => 'Hold a picnic in December.',
    ]));

    app(CancelMeeting::class)($this->secretary, $meeting, 'Postponed for Eid');

    expect($resolution->fresh()?->status)->toBe(ResolutionStatus::Withdrawn);

    $held = app(HoldMeeting::class)($this->secretary, meeting());

    expect(fn () => DB::table('meetings')->where('id', $held->id)->update(['attendees_count' => 99]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('meeting_attendees')->insert(['meeting_id' => $held->id, 'member_id' => onboard()->id]))->toThrow(QueryException::class);
});

it('lets only the secretary or president run meetings', function (): void {
    app(CreateMeeting::class)(userWithRole(Role::Accountant), MeetingData::fromForm([
        'type' => 'committee', 'title' => 'Committee meeting', 'scheduled_at' => '2026-09-12 18:00',
    ]));
})->throws(AuthorizationException::class);
