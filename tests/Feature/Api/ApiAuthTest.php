<?php

declare(strict_types=1);

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Portal\PortalAccounts;
use App\Enums\Role;
use App\Support\Bangla\BanglaNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/*
| App sign-in: mobile + password (or SMS code when switched on), a rotating access/refresh token
| pair, logout per device, and sign-out everywhere when staff reset the password or the member exits.
*/

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07', ['mobile' => '01712345678']);
    app(SetPortalPassword::class)(userWithRole(Role::Secretary), $this->member, 'secret-123');
});

function apiLogin(array $body): TestResponse
{
    return test()->postJson('/api/v1/auth/login', $body);
}

function apiTokens(string $password = 'secret-123'): array
{
    return apiLogin(['mobile' => '01712345678', 'password' => $password])->assertOk()->json('data');
}

it('signs a member in with mobile and password and returns a token pair', function (): void {
    apiLogin(['mobile' => '+880 1712-345678', 'password' => 'secret-123'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'token_type', 'expires_in']])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_in', 3600);

    expect(PersonalAccessToken::query()->count())->toBe(2);
});

it('refuses a wrong password, an unknown mobile, a staff login and an exited member', function (): void {
    apiLogin(['mobile' => '01712345678', 'password' => 'nope'])->assertStatus(422)->assertJsonPath('errors.mobile.0', __('api.auth.failed'));
    apiLogin(['mobile' => '01899999999', 'password' => 'secret-123'])->assertStatus(422);

    $staff = userWithRole(Role::Accountant);
    apiLogin(['mobile' => (string) $staff->mobile, 'password' => 'password'])->assertStatus(422);

    $this->member->forceFill(['status' => MemberStatus::Exited])->save();
    apiLogin(['mobile' => '01712345678', 'password' => 'secret-123'])->assertStatus(422);
});

it('lets an inactive member sign in, as the portal does', function (): void {
    $this->member->forceFill(['status' => MemberStatus::Inactive])->save();

    apiLogin(['mobile' => '01712345678', 'password' => 'secret-123'])->assertOk();
});

it('rate limits login attempts', function (): void {
    foreach (range(1, 5) as $attempt) {
        apiLogin(['mobile' => '01712345678', 'password' => 'wrong'])->assertStatus(422);
    }

    apiLogin(['mobile' => '01712345678', 'password' => 'secret-123'])->assertStatus(429)->assertJsonPath('success', false);
});

it('signs in with an SMS code only when codes are switched on', function (): void {
    $this->seed(SmsTemplateSeeder::class);
    $sms = fakeSms();
    RateLimiter::clear('portal-code:01712345678');

    config(['somiti.portal_otp' => false]);
    $this->postJson('/api/v1/auth/send-code', ['mobile' => '01712345678'])->assertNotFound();
    apiLogin(['mobile' => '01712345678', 'code' => '123456'])->assertStatus(422);

    config(['somiti.portal_otp' => true]);
    $this->postJson('/api/v1/auth/send-code', ['mobile' => '01712345678'])->assertOk();
    preg_match('/[০-৯0-9]{6}/u', (string) (collect($sms->sent)->last()[1] ?? ''), $match);

    apiLogin(['mobile' => '01712345678', 'code' => '000000'])->assertStatus(422);
    apiLogin(['mobile' => '01712345678', 'code' => BanglaNumber::toAscii($match[0] ?? '')])->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});

it('opens data endpoints with the access token only', function (): void {
    $tokens = apiTokens();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.member_no', $this->member->member_no);
    app('auth')->forgetGuards();
    $this->withToken($tokens['refresh_token'])->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
    app('auth')->forgetGuards();
    $this->withToken('')->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('expires access tokens after an hour and rotates them with the refresh token once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-10 10:00'));
    $tokens = apiTokens();

    $this->travelTo(CarbonImmutable::parse('2026-07-10 11:01'));
    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    $fresh = $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data');
    app('auth')->forgetGuards();
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertOk();

    // Replaying the spent refresh token later means it was copied: everything is revoked.
    $this->travel(31)->seconds();
    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('refuses an access token on the refresh endpoint', function (): void {
    $tokens = apiTokens();

    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['access_token']])->assertUnauthorized();
});

it('logs out this device only', function (): void {
    $phone = apiTokens();
    $tablet = apiTokens();

    $this->withToken($phone['access_token'])->postJson('/api/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($phone['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $phone['refresh_token']])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($tablet['access_token'])->getJson('/api/v1/auth/me')->assertOk();
});

it('signs the member out everywhere when staff set a new password or the member exits', function (): void {
    $tokens = apiTokens();
    app(SetPortalPassword::class)(userWithRole(Role::Secretary), $this->member, 'another-456');
    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    $tokens = apiTokens('another-456');
    $this->member->forceFill(['status' => MemberStatus::Exited])->save();
    app('auth')->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertForbidden();

    expect(app(PortalAccounts::class)->forMember($this->member)->tokens()->count())->toBe(0);
});

it('does not sign the member out when a refresh is retried straight away (lost response, parallel refresh)', function (): void {
    $tokens = apiTokens();
    $fresh = $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data');

    $this->travel(10)->seconds();
    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertOk();
    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $fresh['refresh_token']])->assertOk();
});
