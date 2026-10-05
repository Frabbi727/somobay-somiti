<?php

declare(strict_types=1);

use App\Enums\Role;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| SOMITI_SPEC.md §2 security / P7.S2: staff sign in with email + password only, and logins are throttled.
*/

it('signs staff in with email + password alone, even with an old authenticator secret saved', function (): void {
    Filament::setCurrentPanel('admin');
    $user = userWithRole(Role::Cashier);
    DB::table('users')->where('id', $user->id)->update(['app_authentication_secret' => 'old-secret']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->id())->toBe($user->id)
        ->and(Filament::getPanel('admin')->hasMultiFactorAuthentication())->toBeFalse();
});

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
