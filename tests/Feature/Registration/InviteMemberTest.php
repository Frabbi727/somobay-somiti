<?php

declare(strict_types=1);

use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Actions\InviteMember;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
});

it('creates a member login and an open registration from a mobile and password', function (): void {
    $application = invite('+880 1811-111111', 'secret-123');

    expect($application->status)->toBe(MemberApplicationStatus::Invited)
        ->and($application->mobile)->toBe('01811111111')
        ->and($application->user->hasRole(Role::Member->value))->toBeTrue()
        ->and($application->user->email)->toBeNull()
        ->and(Hash::check('secret-123', $application->user->password))->toBeTrue()
        ->and(Member::query()->count())->toBe(0);
});

it('refuses a mobile that is a member or already invited, and a short password', function (string $mobile, string $password, string $key): void {
    onboard(1, '2026-07', ['mobile' => '01799999999']);
    invite('01822222222');

    expect(memberRuleKey(fn () => invite($mobile, $password)))->toBe($key);
})->with([
    'bad mobile' => ['12345', 'secret-123', 'members.errors.mobile_format'],
    'member mobile' => ['01799999999', 'secret-123', 'members.errors.mobile_taken'],
    'invited mobile' => ['01822222222', 'secret-123', 'registration.errors.mobile_invited'],
    'short password' => ['01833333333', '123', 'registration.errors.password_short'],
]);

it('lets only staff who add members invite', function (): void {
    app(InviteMember::class)(userWithRole(Role::Cashier), '01811111111', 'secret-123');
})->throws(AuthorizationException::class);

it('stops the office form from creating a member for a mobile with an open registration', function (): void {
    invite('01844444444');

    expect(memberRuleKey(fn () => onboard(1, '2026-07', ['mobile' => '01844444444'])))->toBe('registration.errors.mobile_invited');
});

it('lets the same mobile be invited again after a rejection', function (): void {
    invite('01855555555')->forceFill(['status' => MemberApplicationStatus::Rejected])->save();

    expect(invite('01855555555')->status)->toBe(MemberApplicationStatus::Invited)
        ->and(MemberApplication::query()->where('mobile', '01855555555')->count())->toBe(2);
});
