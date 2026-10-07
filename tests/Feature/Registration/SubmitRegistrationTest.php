<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Enums\Role;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

it('submits a complete registration to the first step of the chain', function (): void {
    $secretary = userWithRole(Role::Secretary);
    $application = completeRegistration(invite());

    $submitted = app(SubmitRegistration::class)($application, (string) Str::uuid());

    expect($submitted->status)->toBe(MemberApplicationStatus::Submitted)
        ->and($submitted->approval_chain)->toBe(['secretary', 'president'])
        ->and($submitted->current_step)->toBe(0)
        ->and($submitted->submission_no)->toBe(1)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($secretary->notifications()->count())->toBe(1);
});

it('answers a repeated submit with the same key without submitting twice', function (): void {
    userWithRole(Role::Secretary);
    $application = completeRegistration(invite());
    $key = (string) Str::uuid();

    app(SubmitRegistration::class)($application, $key);
    $notified = DatabaseNotification::query()->count();
    $again = app(SubmitRegistration::class)($application, $key);

    expect($again->submission_no)->toBe(1)
        ->and($notified)->toBeGreaterThan(0)
        ->and(DatabaseNotification::query()->count())->toBe($notified);
});

it('refuses a key that another registration used', function (): void {
    $key = (string) Str::uuid();
    app(SubmitRegistration::class)(completeRegistration(invite('01811111111')), $key);

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '9876543211']), $key)))
        ->toBe('registration.errors.idempotency_conflict');
});

it('runs the member rules before submitting', function (array $overrides, string $key): void {
    $application = completeRegistration(invite(), $overrides);

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)($application, (string) Str::uuid())))->toBe($key)
        ->and($application->fresh()?->status)->toBe(MemberApplicationStatus::Invited);
})->with([
    'no english name' => [['name_en' => ''], 'members.errors.names_required'],
    'no nominee' => [['nominees' => []], 'members.errors.nominee_required'],
    'nominee without NID' => [fn () => ['nominees' => [nominee(['nid' => ''])]], 'members.errors.nominee_nid_required'],
    'nominees under 100%' => [fn () => ['nominees' => [nominee(['share_percent' => '40'])]], 'members.errors.nominee_total'],
]);

it('needs the requested share count', function (): void {
    $application = completeRegistration(invite());
    $application->forceFill(['requested_shares' => null])->save();

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)($application, (string) Str::uuid())))->toBe('registration.errors.shares_required');
});

it('refuses an NID that a member or another open registration has', function (): void {
    approvedPlan('2026-07', '500');
    onboard(1, '2026-07', ['nid' => '5555555555']);

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)(completeRegistration(invite('01811111111'), ['nid' => '5555555555']), (string) Str::uuid())))
        ->toBe('members.errors.nid_taken');

    submittedRegistration('01822222222'); // default NID 9876543210

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)(completeRegistration(invite('01833333333')), (string) Str::uuid())))
        ->toBe('registration.errors.nid_taken');
});

it('freezes editing once submitted', function (): void {
    $application = submittedRegistration();

    expect(memberRuleKey(fn () => app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['name_bn' => 'x']))))
        ->toBe('registration.errors.not_editable');
});
