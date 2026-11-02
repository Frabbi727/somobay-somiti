<?php

declare(strict_types=1);

use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Terms\Actions\PublishTerms;
use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Enums\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    userWithRole(Role::Secretary);
    $this->application = invite('01811111111');
    $this->token = app(MemberTokens::class)->issue($this->application->user)['access_token'];
    $this->terms = (int) MembershipTerms::current()->version;
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

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key, 'terms_version' => $this->terms])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'submitted')
        ->assertJsonPath('data.next_action', 'wait')
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.headline', 'Secretary approval pending')
        ->assertJsonPath('data.timeline.1.state', 'pending');

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key, 'terms_version' => $this->terms])->assertOk()->assertJsonPath('data.status.value', 'submitted');
    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid(), 'terms_version' => $this->terms])->assertStatus(422);
    $this->putJson('/api/v1/registration', ['name_bn' => 'x'])->assertStatus(422)->assertJsonPath('message', __('registration.errors.not_editable', [], 'en'));
});

it('explains why a submit is refused', function (): void {
    $this->putJson('/api/v1/registration', registrationInput(['nominees' => [nominee(['share_percent' => '60'])]]))->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid(), 'terms_version' => $this->terms])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('members.errors.nominee_total', ['total' => '60.00%'], 'en'));
});

it('answers a reused key from another registration with 409', function (): void {
    $key = (string) Str::uuid();
    app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), $key, portalConsent());
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key, 'terms_version' => $this->terms])->assertStatus(409);
});

it('keeps members out of the registration screens', function (): void {
    $member = onboard(1, '2026-07', ['mobile' => '01799999999']);
    app('auth')->forgetGuards();

    $this->withToken(memberToken($member))->getJson('/api/v1/registration')->assertStatus(401);
});

it('refuses every registration route to anyone but the applicant', function (): void {
    $refresh = app(MemberTokens::class)->issue($this->application->user)['refresh_token'];
    $member = onboard(1, '2026-07', ['mobile' => '01799999999']);
    $memberToken = memberToken($member);
    $staff = userWithRole(Role::Accountant);

    $routes = [['getJson', '/api/v1/registration'], ['putJson', '/api/v1/registration'], ['postJson', '/api/v1/registration/photo'], ['postJson', '/api/v1/registration/submit']];

    foreach ($routes as [$method, $uri]) {
        app('auth')->forgetGuards();
        $this->withToken('')->{$method}($uri)->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($refresh)->{$method}($uri)->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($memberToken)->{$method}($uri)->assertUnauthorized();
        app('auth')->forgetGuards();
        $staffToken = $staff->createToken('access:staff', ['applicant'], now()->addHour())->plainTextToken;
        $status = $this->withToken($staffToken)->{$method}($uri)->getStatusCode();
        expect($status)->not->toBe(200);
    }
});

it('serves the photo only through an untampered signed link for that registration', function (): void {
    Storage::fake('local');
    $url = $this->post('/api/v1/registration/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])->json('data.data.photo_url');
    $other = invite('01833333333');

    $this->get($url.'x')->assertForbidden();
    $this->get(str_replace('/photo/'.$this->application->id.'?', '/photo/'.$other->id.'?', $url))->assertForbidden();
    $this->get($url)->assertOk();
});

it('leaves no file behind when the photo is refused', function (): void {
    Storage::fake('local');
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();
    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid(), 'terms_version' => $this->terms])->assertOk();

    $this->post('/api/v1/registration/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)], ['Accept' => 'application/json'])->assertStatus(422);

    expect(Storage::disk('local')->allFiles('member-photos'))->toBe([]);
});

it('ignores a photo_path sent in the draft', function (): void {
    $this->putJson('/api/v1/registration', ['photo_path' => 'member-photos/x.jpg', 'name_bn' => 'করিম'])
        ->assertOk()
        ->assertJsonPath('data.data.photo_url', null);

    expect($this->application->fresh()?->photo_path)->toBeNull();
});

it('saves the paper-form fields and returns them with the place names', function (): void {
    $this->putJson('/api/v1/registration', registrationInput(['permanent_same_as_present' => true, 'permanent_upazila_id' => null, 'passport_no' => 'ab 123456']))
        ->assertOk()
        ->assertJsonPath('data.data.gender.value', 'male')
        ->assertJsonPath('data.data.passport_no', 'AB123456')
        ->assertJsonPath('data.data.present_address.upazila_id', 495)
        ->assertJsonPath('data.data.present_address.union_id', 4541)
        ->assertJsonPath('data.data.present_address.address_line', 'House 12, Road 3')
        ->assertJsonPath('data.data.permanent_address.upazila_id', 495)
        ->assertJsonPath('data.data.nominees.0.gender.value', 'female')
        ->assertJsonPath('data.data.nominees.0.present_address.upazila_id', 495)
        ->assertJsonStructure(['data' => ['data' => ['present_address' => ['division_id', 'district_id', 'place_name']]]]);

    expect($this->getJson('/api/v1/registration')->json('data.data.present_address.place_name'))->toContain('Gulshan');
});

it('returns null for what is not filled yet', function (): void {
    $this->getJson('/api/v1/registration')
        ->assertJsonPath('data.data.gender', null)
        ->assertJsonPath('data.data.present_address', null)
        ->assertJsonPath('data.data.permanent_address', null);
});

it('returns an address line saved without an upazila, so a reload cannot erase it', function (): void {
    $this->putJson('/api/v1/registration', ['present_address_line' => 'House 5'])->assertOk();

    $this->getJson('/api/v1/registration')
        ->assertJsonPath('data.data.present_address.address_line', 'House 5')
        ->assertJsonPath('data.data.present_address.upazila_id', null)
        ->assertJsonPath('data.data.present_address.place_name', null);
});

it('names the paper-form fields that are wrong', function (): void {
    $this->putJson('/api/v1/registration', ['gender' => 'robot', 'passport_no' => str_repeat('A', 21), 'present_upazila_id' => 999999, 'permanent_union_id' => 999999, 'permanent_address_line' => str_repeat('a', 256), 'nominees' => [nominee(['gender' => 'x', 'present_upazila_id' => 999999])]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['gender', 'passport_no', 'present_upazila_id', 'permanent_union_id', 'permanent_address_line', 'nominees.0.gender', 'nominees.0.present_upazila_id'], 'errors');
});

it('needs the rules version to submit and refuses a replaced one', function (): void {
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['terms_version'], 'errors');

    $president = userWithRole(Role::President);
    app(SaveTermsDraft::class)($president, 'নিয়ম ২', '2. Rule', 'ঘোষণা ২', 'Declaration 2');
    app(PublishTerms::class)($president, MembershipTerms::draft());

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid(), 'terms_version' => $this->terms])
        ->assertStatus(422)
        ->assertJsonPath('message', __('terms.errors.outdated', [], 'en'));

    expect($this->application->fresh()?->status->value)->toBe('invited');
});
