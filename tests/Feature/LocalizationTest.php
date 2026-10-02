<?php

declare(strict_types=1);

use App\Enums\Role;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Support\Arr;

/**
 * @return list<string>
 */
function translationKeys(string $locale, string $file): array
{
    /** @var array<string, mixed> $lines */
    $lines = require lang_path("{$locale}/{$file}");

    return array_keys(Arr::dot($lines));
}

it('has every translation key in both Bangla and English', function (): void {
    $files = fn (string $locale): array => array_map('basename', glob(lang_path("{$locale}/*.php")) ?: []);

    expect($files('bn'))->toEqualCanonicalizing($files('en'));

    foreach ($files('en') as $file) {
        expect(translationKeys('bn', $file))->toEqualCanonicalizing(translationKeys('en', $file), "lang/bn/{$file} and lang/en/{$file} differ");
    }
});

it('uses Bangla by default for a signed-in staff member', function (): void {
    $this->actingAs(userWithRole(Role::Accountant))
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/admin')
        ->assertOk()
        ->assertSee('lang="bn"', false);
});

it('remembers the language a staff member switches to', function (): void {
    $user = userWithRole(Role::Accountant);
    $this->actingAs($user);

    LanguageSwitch::switchLocale('en');

    expect($user->fresh()?->locale)->toBe('en');

    $this->get('/admin')->assertSee('lang="en"', false);
});
