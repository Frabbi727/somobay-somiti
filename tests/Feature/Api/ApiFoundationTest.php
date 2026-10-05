<?php

declare(strict_types=1);

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Settings\Actions\UpdateSomitiProfile;
use App\Domain\Settings\Data\SomitiProfileData;
use App\Enums\Role;
use App\Filament\Support\Display;
use App\Http\Api\ApiValue;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;

/*
| The member API's shared behaviour: the app's response envelope, language from Accept-Language,
| errors in the envelope, and the JSON shapes for money, enums and months.
*/

it('returns the society details in the app envelope, in Bangla by default', function (): void {
    app(UpdateSomitiProfile::class)(userWithRole(Role::SuperAdmin), new SomitiProfileData(nameBn: 'সবুজ সমবায় সমিতি', nameEn: 'Sabuj Society', phone: '01712345678'));
    config(['somiti.portal_otp' => true]);

    $this->withHeaders(['Accept-Language' => ''])->getJson('/api/v1/config/somiti-info')
        ->assertOk()
        ->assertExactJsonStructure(['success', 'statusCode', 'message', 'data' => ['name', 'name_bn', 'name_en', 'registration_no', 'address', 'phone', 'email', 'logo_url', 'otp_enabled'], 'errors', 'meta'])
        ->assertJson(['success' => true, 'statusCode' => 200, 'data' => ['name' => 'সবুজ সমবায় সমিতি', 'otp_enabled' => true], 'errors' => null, 'meta' => null]);
});

it('picks the language from Accept-Language and falls back to Bangla', function (string $header, string $name): void {
    app(UpdateSomitiProfile::class)(userWithRole(Role::SuperAdmin), new SomitiProfileData(nameBn: 'সবুজ', nameEn: 'Sabuj'));

    // Symfony's test client sends "en-us" when no header is given, so "missing" is tested as empty.
    $this->withHeaders(['Accept-Language' => $header])
        ->getJson('/api/v1/config/somiti-info')
        ->assertJsonPath('data.name', $name);
})->with([['', 'সবুজ'], ['en', 'Sabuj'], ['en-US,en;q=0.9', 'Sabuj'], ['bn-BD', 'সবুজ'], ['fr', 'সবুজ']]);

it('answers unknown routes and wrong methods in the envelope', function (): void {
    $this->getJson('/api/v1/nope')->assertNotFound()->assertJson(['success' => false, 'statusCode' => 404, 'data' => null]);
    $this->postJson('/api/v1/config/somiti-info')->assertStatus(405)->assertJson(['success' => false, 'statusCode' => 405]);
});

it('formats money, enums and months for the app', function (): void {
    app()->setLocale('bn');
    $amount = Money::ofTaka('1005.25');

    expect(ApiValue::money($amount))->toBe(['poisha' => 100525, 'display' => Display::money($amount)])
        ->and(ApiValue::money(null))->toBeNull()
        ->and(ApiValue::month(YearMonth::parse('2026-07')))->toBe('2026-07')
        ->and(ApiValue::enum(DueStatus::Open))->toMatchArray(['value' => 'open', 'label' => DueStatus::Open->getLabel()]);
});

it('answers sign-in, rate-limit and routing errors in the requested language too', function (): void {
    $this->withHeaders(['Accept-Language' => 'en'])->withToken('nope')->getJson('/api/v1/profile')
        ->assertUnauthorized()->assertJsonPath('message', __('api.errors.unauthenticated', [], 'en'));
    $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/v1/nope')
        ->assertNotFound()->assertJsonPath('message', __('api.errors.not_found', [], 'en'));

    foreach (range(1, 6) as $attempt) {
        $last = $this->withHeaders(['Accept-Language' => 'en'])->postJson('/api/v1/auth/login', ['mobile' => '01799999999', 'password' => 'x']);
    }
    $last->assertStatus(429)->assertJsonPath('message', __('api.errors.throttled', [], 'en'));
});
