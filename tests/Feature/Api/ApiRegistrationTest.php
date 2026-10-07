<?php

declare(strict_types=1);

use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    userWithRole(Role::Secretary);
    $this->application = invite('01811111111');
    $this->token = app(MemberTokens::class)->issue($this->application->user)['access_token'];
    $this->withToken($this->token)->withHeader('Accept-Language', 'en');
});

it('shows an empty registration with the steps ahead', function (): void {
    $this->getJson('/api/v1/registration')
        ->assertOk()
        ->assertJsonPath('data.status.value', 'invited')
        ->assertJsonPath('data.next_action', 'complete')
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.headline', 'Your registration is incomplete')
        ->assertJsonCount(4, 'data.timeline')
        ->assertJsonPath('data.timeline.1.label', 'Secretary approval')
        ->assertJsonPath('data.timeline.1.state', 'waiting')
        ->assertJsonPath('data.data.mobile', '01811111111')
        ->assertJsonPath('data.data.nominees', []);
});

it('saves a draft and returns it', function (): void {
    $this->putJson('/api/v1/registration', ['name_bn' => 'করিম মিয়া', 'requested_shares' => 2, 'nominees' => [nominee()]])
        ->assertOk()
        ->assertJsonPath('data.data.name_bn', 'করিম মিয়া')
        ->assertJsonPath('data.data.requested_shares', 2)
        ->assertJsonPath('data.data.nominees.0.relation', 'Spouse')
        ->assertJsonPath('data.data.nominees.0.share_percent', '100.00');
});

it('names the fields that are wrong', function (): void {
    $this->putJson('/api/v1/registration', ['nid' => '12', 'date_of_birth' => '2999-01-01', 'nominees' => [nominee(['relation_id' => 9999, 'nid' => '1'])]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nid', 'date_of_birth', 'nominees.0.relation_id', 'nominees.0.nid'], 'errors');
});

it('stores the photo privately and serves it through a signed link', function (): void {
    Storage::fake('local');

    $url = $this->post('/api/v1/registration/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])
        ->assertOk()
        ->json('data.data.photo_url');

    expect($url)->toContain('signature=');
    Storage::disk('local')->assertExists((string) $this->application->fresh()?->photo_path);
    $this->get($url)->assertOk();
});

it('submits once per key and then waits for the secretary', function (): void {
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'submitted')
        ->assertJsonPath('data.next_action', 'wait')
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.headline', 'Secretary approval pending')
        ->assertJsonPath('data.timeline.1.state', 'pending');

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key])->assertOk()->assertJsonPath('data.status.value', 'submitted');
    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid()])->assertStatus(422);
    $this->putJson('/api/v1/registration', ['name_bn' => 'x'])->assertStatus(422)->assertJsonPath('message', __('registration.errors.not_editable', [], 'en'));
});

it('explains why a submit is refused', function (): void {
    $this->putJson('/api/v1/registration', registrationInput(['nominees' => [nominee(['share_percent' => '60'])]]))->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('members.errors.nominee_total', ['total' => '60.00%'], 'en'));
});

it('answers a reused key from another registration with 409', function (): void {
    $key = (string) Str::uuid();
    app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), $key);
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key])->assertStatus(409);
});

it('keeps members out of the registration screens', function (): void {
    $member = onboard(1, '2026-07', ['mobile' => '01799999999']);
    app('auth')->forgetGuards();

    $this->withToken(memberToken($member))->getJson('/api/v1/registration')->assertStatus(401);
});
