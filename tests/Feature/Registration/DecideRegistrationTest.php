<?php

declare(strict_types=1);

use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
});

function decide(Role $role, $application, RegistrationDecisionType $decision, ?string $reason = null, ?int $shares = null, ?string $from = null)
{
    return app(DecideRegistration::class)(userWithRole($role), $application, $decision, $reason, $shares, $from === null ? null : YearMonth::parse($from));
}

it('moves through secretary then president, and only then creates the member', function (): void {
    $application = submittedRegistration();

    $application = decide(Role::Secretary, $application, RegistrationDecisionType::Approve);

    expect($application->status)->toBe(MemberApplicationStatus::Submitted)
        ->and($application->current_step)->toBe(1)
        ->and(Member::query()->count())->toBe(0)
        ->and(Due::query()->count())->toBe(0)
        ->and(ShareLot::query()->count())->toBe(0);

    $application = decide(Role::President, $application, RegistrationDecisionType::Approve, null, 3, '2026-07');
    $member = Member::query()->sole();

    expect($application->status)->toBe(MemberApplicationStatus::Approved)
        ->and($application->member_id)->toBe($member->id)
        ->and($member->status)->toBe(MemberStatus::Active)
        ->and($member->name_en)->toBe('Karim Mia')
        ->and($member->mobile)->toBe('01811111111')
        ->and($member->user_id)->toBe($application->user_id)
        ->and($member->nominees()->sole()->relation_id)->toBe(relationId('spouse'))
        ->and($member->sharesIn(YearMonth::of(2026, 7)))->toBe(3)
        ->and(Due::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and($application->user->fresh()?->name)->toBe('Karim Mia')
        ->and(SmsMessage::query()->where('template_key', 'welcome')->count())->toBe(1);
});

it('uses the requested share count when the approver keeps it', function (): void {
    $member = Member::query()->findOrFail(approveRegistration(submittedRegistration())->member_id);

    expect($member->sharesIn(YearMonth::of(2026, 7)))->toBe(2);
});

it('lets the applicant keep their sign-in after approval: access tokens go, the refresh token stays', function (): void {
    $application = submittedRegistration();
    app(MemberTokens::class)->issue($application->user);

    approveRegistration($application);

    expect($application->user->tokens()->pluck('name')->map(fn (string $name): string => strtok($name, ':'))->all())->toBe(['refresh']);
});

it('only lets the role at the current step decide, one step per person', function (): void {
    $application = submittedRegistration();

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Approve)))->toBe('registration.errors.not_your_step');

    $both = userWithRole(Role::Secretary);
    $both->assignRole(Role::President->value);
    app(DecideRegistration::class)($both, $application, RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => app(DecideRegistration::class)($both, $application->fresh(), RegistrationDecisionType::Approve, null, 2, YearMonth::parse('2026-07'))))
        ->toBe('registration.errors.already_decided');
});

it('needs the start month on the final approval', function (): void {
    $application = decide(Role::Secretary, submittedRegistration(), RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Approve)))->toBe('registration.errors.effective_from_required');
});

it('creates nothing when there is no approved rate plan for the start month', function (): void {
    $application = decide(Role::Secretary, submittedRegistration(), RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Approve, null, 1, '2026-06')))->toBe('members.errors.no_rate_plan');

    $fresh = $application->fresh();

    expect($fresh?->status)->toBe(MemberApplicationStatus::Submitted)
        ->and($fresh?->current_step)->toBe(1)
        ->and($fresh?->decisions()->count())->toBe(1)
        ->and(Member::query()->count())->toBe(0)
        ->and(Due::query()->count())->toBe(0);
});

it('sends a registration back with a reason; the member fixes it and the chain starts again', function (): void {
    $application = decide(Role::Secretary, submittedRegistration(), RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Return, 'no')))->toBe('registration.errors.reason_required');

    $application = decide(Role::President, $application, RegistrationDecisionType::Return, 'Photo is missing');

    expect($application->status)->toBe(MemberApplicationStatus::Returned)
        ->and(SmsMessage::query()->where('template_key', 'registration_returned')->sole()->body)->toContain('Photo is missing');

    app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['address' => 'Uttara, Dhaka']));
    $application = app(SubmitRegistration::class)($application, (string) Str::uuid());

    expect($application->submission_no)->toBe(2)
        ->and($application->current_step)->toBe(0)
        ->and($application->decisions()->count())->toBe(2);
});

it('rejects permanently: signs the applicant out and frees the mobile', function (): void {
    $application = submittedRegistration();
    app(MemberTokens::class)->issue($application->user);

    $application = decide(Role::Secretary, $application, RegistrationDecisionType::Reject, 'Not a resident of the area');

    expect($application->status)->toBe(MemberApplicationStatus::Rejected)
        ->and($application->user->tokens()->count())->toBe(0)
        ->and(SmsMessage::query()->where('template_key', 'registration_rejected')->count())->toBe(1)
        ->and(invite('01811111111')->status)->toBe(MemberApplicationStatus::Invited);
});

it('refuses decisions on a registration that is not waiting', function (): void {
    expect(memberRuleKey(fn () => decide(Role::Secretary, completeRegistration(invite()), RegistrationDecisionType::Approve)))->toBe('registration.errors.not_pending');
});
