<?php

declare(strict_types=1);

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Livewire\Portal\Login;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
| Portal sign-in without SMS: the secretary sets a password, the member signs in with mobile + password.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07', ['mobile' => '01712345678']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('lets the secretary set a portal password that the member then signs in with', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMember::class, ['record' => $this->member->getRouteKey()])
        ->callAction('setPortalPassword', data: ['password' => 'rahim2026', 'password_confirmation' => 'rahim2026'])
        ->assertHasNoActionErrors();

    auth()->logout();

    Livewire::test(Login::class)
        ->set('mobile', '01712345678')
        ->set('password', 'rahim2026')
        ->call('loginWithPassword')
        ->assertRedirect(route('portal.dashboard'));
});

it('shows only mobile + password when SMS codes are switched off', function (): void {
    config(['somiti.portal_otp' => false]);

    Livewire::test(Login::class)
        ->assertSet('usePassword', true)
        ->assertSee(__('portal.login.password_help', [], 'bn'))
        ->assertDontSee(__('portal.login.use_code', [], 'bn'))
        ->call('sendCode')
        ->assertSet('codeSent', false);
});

it('still offers SMS codes when they are switched on', function (): void {
    config(['somiti.portal_otp' => true]);

    Livewire::test(Login::class)->assertSet('usePassword', false);
});

it('refuses short passwords and exited members, and only the secretary or president may set one', function (): void {
    $secretary = userWithRole(Role::Secretary);

    expect(fn () => app(SetPortalPassword::class)($secretary, $this->member, '12345'))->toThrow(DomainRuleViolation::class)
        ->and(fn () => app(SetPortalPassword::class)(userWithRole(Role::Cashier), $this->member, 'long-enough'))->toThrow(AuthorizationException::class);

    app(SetPortalPassword::class)($secretary, $this->member, 'long-enough');
    $this->member->forceFill(['status' => MemberStatus::Exited])->save();

    Livewire::test(Login::class)
        ->set('mobile', '01712345678')
        ->set('password', 'long-enough')
        ->call('loginWithPassword')
        ->assertSet('error', __('portal.errors.wrong_password'));
});
