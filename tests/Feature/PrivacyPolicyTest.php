<?php

declare(strict_types=1);

use App\Domain\Settings\Models\SomitiProfile;

/*
| The public privacy policy (linked from both sign-in pages and given to the Play Store).
*/

it('shows the privacy policy to anyone, in Bangla by default and in English on request', function (): void {
    SomitiProfile::query()->create(['id' => SomitiProfile::ID, 'name_bn' => 'সমবায় সমিতি', 'name_en' => 'Somobay Somiti', 'email' => 'office@example.com']);

    $this->get('/privacy-policy')
        ->assertOk()
        ->assertSee('lang="bn"', false)
        ->assertSee(__('privacy.title', [], 'bn'))
        ->assertSee('সমবায় সমিতি')
        ->assertSee('office@example.com')
        ->assertSee(route('privacy', ['lang' => 'en']), false);

    $this->get('/privacy-policy?lang=en')
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee(__('privacy.title', [], 'en'))
        ->assertSee('Somobay Somiti');
});

it('links the privacy policy from both sign-in pages', function (): void {
    $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee(route('privacy'), false);
    $this->get(route('filament.member.auth.login'))->assertOk()->assertSee(route('privacy'), false);
});
