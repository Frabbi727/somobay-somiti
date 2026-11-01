<?php

declare(strict_types=1);

use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Terms\Actions\AcceptTerms;
use App\Domain\Members\Terms\Actions\PublishTerms;
use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Enums\Role;
use App\Models\User;
use App\Support\Time\YearMonth;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
});

function publishNextTerms(): MembershipTerms
{
    $president = userWithRole(Role::President);
    app(SaveTermsDraft::class)($president, 'নিয়ম ২', '2. Rule', 'ঘোষণা ২', 'Declaration 2');

    return app(PublishTerms::class)($president, MembershipTerms::draft());
}

it('records the applicant\'s acceptance when submitting', function (): void {
    $application = submittedRegistration();

    $acceptance = TermsAcceptance::query()->where('application_id', $application->id)->sole();

    expect($acceptance->terms_id)->toBe(MembershipTerms::current()->id)
        ->and($acceptance->member_id)->toBeNull()
        ->and($acceptance->user_id)->toBe($application->user_id)
        ->and($acceptance->channel)->toBe(ConsentChannel::Portal);
});

it('refuses acceptance of a superseded version', function (): void {
    $application = completeRegistration(invite());
    $consent = portalConsent();
    publishNextTerms();

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)($application, (string) Str::uuid(), $consent)))
        ->toBe('terms.errors.outdated')
        ->and($application->fresh()->status)->toBe(MemberApplicationStatus::Invited)
        ->and(TermsAcceptance::query()->count())->toBe(0);
});

it('counts an approved member as having accepted through the application', function (): void {
    $application = approveRegistration(submittedRegistration());
    $member = $application->member;

    expect(app(TermsGate::class)->needsAcceptance($member))->toBeFalse()
        ->and(TermsAcceptance::query()->whereNotNull('member_id')->count())->toBe(0);
});

it('records an office acceptance when the secretary creates a member', function (): void {
    $secretary = userWithRole(Role::Secretary);
    $member = app(CreateMember::class)(
        $secretary,
        memberData(),
        1,
        YearMonth::parse('2026-07'),
        null,
        new TermsConsent((int) MembershipTerms::current()->version, ConsentChannel::Office, (string) Str::uuid()),
    );

    $acceptance = TermsAcceptance::query()->where('member_id', $member->id)->sole();

    expect($acceptance->channel)->toBe(ConsentChannel::Office)
        ->and($acceptance->user_id)->toBe($secretary->id)
        ->and(app(TermsGate::class)->needsAcceptance($member))->toBeFalse();
});

it('says an existing member without acceptance needs to accept', function (): void {
    expect(app(TermsGate::class)->needsAcceptance(onboard()))->toBeTrue();
});

it('makes everyone accept again after new rules are published', function (): void {
    $member = onboard();
    $user = User::query()->findOrFail($member->user_id);
    app(AcceptTerms::class)($user, $member, new TermsConsent(1, ConsentChannel::Portal, (string) Str::uuid()));

    expect(app(TermsGate::class)->needsAcceptance($member))->toBeFalse();

    publishNextTerms();

    expect(app(TermsGate::class)->needsAcceptance($member))->toBeTrue();
});

it('stores one row when accepting twice with the same key', function (): void {
    $member = onboard();
    $user = User::query()->findOrFail($member->user_id);
    $consent = new TermsConsent(1, ConsentChannel::App, (string) Str::uuid(), '203.0.113.9');

    $first = app(AcceptTerms::class)($user, $member, $consent);
    $second = app(AcceptTerms::class)($user, $member, $consent);
    $third = app(AcceptTerms::class)($user, $member, new TermsConsent(1, ConsentChannel::App, (string) Str::uuid()));

    expect($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and(TermsAcceptance::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and($first->ip)->toBe('203.0.113.9')
        ->and(Activity::query()->where('event', 'terms_accepted')->count())->toBe(1);
});

it('refuses a stale version and another person\'s acceptance', function (): void {
    $member = onboard();
    $user = User::query()->findOrFail($member->user_id);
    publishNextTerms();

    expect(memberRuleKey(fn () => app(AcceptTerms::class)($user, $member, new TermsConsent(1, ConsentChannel::Portal, (string) Str::uuid()))))
        ->toBe('terms.errors.outdated');

    expect(fn () => app(AcceptTerms::class)(userWithRole(Role::Secretary), $member, new TermsConsent(2, ConsentChannel::Portal, (string) Str::uuid())))
        ->toThrow(AuthorizationException::class);
});
