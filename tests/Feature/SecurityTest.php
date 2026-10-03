<?php

declare(strict_types=1);

use App\Enums\Role;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
| SOMITI_SPEC.md §2 security / P7.S2: staff must use an authenticator app, and logins are throttled.
*/

it('requires two-factor authentication for staff unless switched off', function (): void {
    $panel = Filament::getPanel('admin');

    config(['somiti.require_mfa' => true]);
    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue();

    config(['somiti.require_mfa' => false]);
    expect($panel->isMultiFactorAuthenticationRequired())->toBeFalse();
});

it('forces staff without an authenticator app to set one up, and is on by default', function (bool $required): void {
    $routes = Process::env(['SOMITI_REQUIRE_MFA' => $required ? 'true' : 'false'])
        ->path(base_path())
        ->run(['php', 'artisan', 'route:list', '--name=filament.admin.auth', '--json']);

    expect($routes->successful())->toBeTrue()
        ->and(str_contains($routes->output(), 'multi-factor-authentication\\/set-up'))->toBe($required)
        ->and(file_get_contents(config_path('somiti.php')))->toContain("env('SOMITI_REQUIRE_MFA', true)");
})->with([true, false]);

it('throttles staff logins after five failed attempts', function (): void {
    Filament::setCurrentPanel('admin');
    $user = userWithRole(Role::Cashier);

    foreach (range(1, 5) as $attempt) {
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    }

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertNotified();

    $this->assertGuest();
});
