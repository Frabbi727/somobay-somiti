<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Enums\Role;
use Laravel\Sanctum\PersonalAccessToken;

/*
| A registration's login signs in like a member's but gets an "applicant" token that only opens the
| registration screens (spec §7). After the final approval the next refresh gives a member token.
*/

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->application = invite('01811111111', 'secret-123');
});

function applicantLogin(): array
{
    return test()->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'secret-123'])->assertOk()->json('data');
}

it('signs an invited member in with an applicant token', function (): void {
    $tokens = applicantLogin();

    expect($tokens['account_type'])->toBe('applicant')
        ->and(PersonalAccessToken::findToken($tokens['access_token'])?->abilities)->toBe(['applicant']);

    $this->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'wrong'])->assertStatus(422);
});

it('tells the app where the registration stands', function (): void {
    $this->withToken(applicantLogin()['access_token'])
        ->getJson('/api/v1/auth/me', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.account_type', 'applicant')
        ->assertJsonPath('data.mobile', '01811111111')
        ->assertJsonPath('data.registration.status.value', 'invited')
        ->assertJsonPath('data.registration.next_action', 'complete');
});

it('answers member screens with 403 "update the app" and keeps the applicant signed in', function (): void {
    $tokens = applicantLogin();

    $this->withToken($tokens['access_token'])
        ->getJson('/api/v1/dashboard/summary')
        ->assertStatus(403)
        ->assertJsonPath('message', __('api.registration.member_only'));

    expect(PersonalAccessToken::query()->count())->toBe(2);
});

it('turns into a member token on the next refresh after the final approval', function (): void {
    $tokens = applicantLogin();
    approveRegistration(submittedRegistrationFrom($this->application));
    app('auth')->forgetGuards();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);

    $fresh = $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data');
    app('auth')->forgetGuards();

    expect($fresh['account_type'])->toBe('member');
    $this->withToken($fresh['access_token'])->getJson('/api/v1/dashboard/summary')->assertOk();
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertJsonPath('data.account_type', 'member');
});

it('refuses a rejected applicant everywhere', function (): void {
    $tokens = applicantLogin();
    app(DecideRegistration::class)(userWithRole(Role::Secretary), submittedRegistrationFrom($this->application), RegistrationDecisionType::Reject, 'Not from this area');
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'secret-123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.mobile.0', __('portal.errors.registration_rejected'));
    $this->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'wrong-pass'])
        ->assertStatus(422)
        ->assertJsonPath('errors.mobile.0', __('api.auth.failed'));
    expect(PersonalAccessToken::query()->count())->toBe(0);
});
