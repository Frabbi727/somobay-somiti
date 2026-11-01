<?php

declare(strict_types=1);

use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Terms\Actions\PublishTerms;
use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Enums\Role;
use App\Filament\Member\Pages\AcceptRules;
use App\Filament\Member\Pages\Dashboard;
use App\Filament\Member\Pages\Profile;
use App\Filament\Member\Pages\RegistrationStatus;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| Existing members who never accepted the rules are asked on their next portal visit or app call;
| the app can still read the rules, accept, sign out and ask who it is.
*/

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
    $this->member = onboardUnaccepted();
    $this->user = app(PortalAccounts::class)->forMember($this->member);
    $this->token = app(MemberTokens::class)->issue($this->user)['access_token'];
});

function acceptViaApi(string $token, array $body = []): TestResponse
{
    return test()->withToken($token)->postJson('/api/v1/terms/accept', [
        'version' => MembershipTerms::current()->version,
        'idempotency_key' => (string) Str::uuid(),
        ...$body,
    ]);
}

it('me reports whether the rules must be accepted', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.terms_acceptance_required', true)
        ->assertJsonPath('data.terms_version', MembershipTerms::current()->version);

    acceptViaApi($this->token)->assertOk();

    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertJsonPath('data.terms_acceptance_required', false);
});

it('portal sends a member who has not accepted to the rules page', function (): void {
    $this->actingAs($this->user)->get('/portal')->assertRedirect(AcceptRules::getUrl());
    $this->actingAs($this->user)->get(Profile::getUrl())->assertRedirect(AcceptRules::getUrl());
    $this->actingAs($this->user)->get(AcceptRules::getUrl())->assertOk()->assertSee(MembershipTerms::current()->rules_bn);
});

it('accepting in the portal lets the member through', function (): void {
    $this->actingAs($this->user);

    Livewire::test(AcceptRules::class)
        ->set('data.agree', true)
        ->callAction('accept')
        ->assertHasNoActionErrors()
        ->assertRedirect(Dashboard::getUrl());

    expect(TermsAcceptance::query()->where('member_id', $this->member->id)->count())->toBe(1)
        ->and(app(TermsGate::class)->needsAcceptance($this->member))->toBeFalse();

    $this->get('/portal/profile')->assertOk();
});

it('portal accept page needs the agreement ticked', function (): void {
    $this->actingAs($this->user);

    Livewire::test(AcceptRules::class)->set('data.agree', false)->callAction('accept')->assertHasActionErrors();

    expect(TermsAcceptance::query()->where('member_id', $this->member->id)->count())->toBe(0);
});

it('api blocks member routes until accepted', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/dashboard/summary')
        ->assertForbidden()
        ->assertJsonPath('meta.code', 'terms_acceptance_required')
        ->assertJsonPath('meta.version', (string) MembershipTerms::current()->version);

    acceptViaApi($this->token)->assertOk();

    $this->withToken($this->token)->getJson('/api/v1/dashboard/summary')->assertOk();
});

it('api still allows terms, logout and me', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/terms/current')
        ->assertOk()
        ->assertJsonPath('data.version', MembershipTerms::current()->version)
        ->assertJsonPath('data.rules_bn', MembershipTerms::current()->rules_bn)
        ->assertJsonPath('data.accepted', false);
    $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertOk();

    acceptViaApi($this->token)->assertOk();
    $this->withToken($this->token)->getJson('/api/v1/terms/current')->assertJsonPath('data.accepted', true);

    $this->withToken($this->token)->postJson('/api/v1/auth/logout')->assertOk();
});

it('api accept is idempotent and validates its input', function (): void {
    $key = (string) Str::uuid();
    acceptViaApi($this->token, ['idempotency_key' => $key])->assertOk();
    acceptViaApi($this->token, ['idempotency_key' => $key])->assertOk();

    expect(TermsAcceptance::query()->where('member_id', $this->member->id)->count())->toBe(1);

    $this->withToken($this->token)->postJson('/api/v1/terms/accept', ['version' => 'x', 'idempotency_key' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors(['version', 'idempotency_key']);
});

it('api accept with a stale version is refused', function (): void {
    $stale = MembershipTerms::current()->version;
    $president = userWithRole(Role::President);
    app(SaveTermsDraft::class)($president, 'নিয়ম ২', '2. Rule', 'ঘোষণা ২', 'Declaration 2');
    app(PublishTerms::class)($president, MembershipTerms::draft());

    acceptViaApi($this->token, ['version' => $stale])->assertStatus(422);

    expect(TermsAcceptance::query()->where('member_id', $this->member->id)->count())->toBe(0);
});

it('applicants are not gated', function (): void {
    $application = invite('01811111111', 'secret-123');
    $token = test()->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'secret-123'])->assertOk()->json('data.access_token');

    $this->withToken($token)->getJson('/api/v1/registration')->assertOk();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.terms_acceptance_required', false);
    $this->withToken($token)->getJson('/api/v1/terms/current')->assertOk()->assertJsonPath('data.accepted', null);
});

it('portal lets an applicant reach the registration page without the rules gate', function (): void {
    $application = invite('01811111111', 'secret-123');

    $this->actingAs($application->user)->get('/portal')->assertRedirect(RegistrationStatus::getUrl());
});

it('shows the accepted version on the profile', function (): void {
    acceptViaApi($this->token)->assertOk();

    Livewire::actingAs($this->user)->test(Profile::class)->assertSee(MembershipTerms::current()->rules_en);
});
