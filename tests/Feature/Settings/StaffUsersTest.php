<?php

declare(strict_types=1);

use App\Domain\Settings\Actions\CreateStaffUser;
use App\Domain\Settings\Actions\SetStaffUserActive;
use App\Domain\Settings\Actions\UpdateStaffUser;
use App\Domain\Settings\Data\StaffUserData;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\Users\Pages\CreateUser;
use App\Filament\Clusters\Settings\Resources\Users\Pages\EditUser;
use App\Filament\Clusters\Settings\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->admin = userWithRole(Role::SuperAdmin);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function staffData(array $overrides = []): StaffUserData
{
    return StaffUserData::fromForm([
        'name' => 'Karim Cashier',
        'email' => 'karim@somiti.test',
        'mobile' => '+880 1712-345678',
        'roles' => ['cashier'],
        'locale' => 'bn',
        'password' => 'a-long-password',
        ...$overrides,
    ]);
}

function staffRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('creates a staff account with roles and a normalised mobile', function (): void {
    $user = app(CreateStaffUser::class)($this->admin, staffData(['email' => 'KARIM@Somiti.test']));

    expect($user->email)->toBe('karim@somiti.test')
        ->and($user->mobile)->toBe('01712345678')
        ->and($user->hasAnyOf(Role::Cashier))->toBeTrue()
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue();
});

it('enforces the account rules', function (array $overrides, string $key): void {
    userWithRole(Role::Accountant)->update(['email' => 'taken@somiti.test']);

    expect(staffRuleKey(fn () => app(CreateStaffUser::class)($this->admin, staffData($overrides))))->toBe($key);
})->with([
    'no roles' => [['roles' => []], 'users.errors.staff_role_required'],
    'member role' => [['roles' => ['member']], 'users.errors.staff_role_required'],
    'bad mobile' => [['mobile' => '12345'], 'users.errors.mobile_invalid'],
    'email taken' => [['email' => 'Taken@somiti.test'], 'users.errors.email_taken'],
    'short password' => [['password' => 'short'], 'users.errors.password_short'],
    'no password' => [['password' => null], 'users.errors.password_required'],
]);

it('lets only the super admin manage staff accounts', function (): void {
    app(CreateStaffUser::class)(userWithRole(Role::President), staffData());
})->throws(AuthorizationException::class);

it('always keeps one active super admin', function (): void {
    expect(staffRuleKey(fn () => app(UpdateStaffUser::class)($this->admin, $this->admin, staffData(['email' => $this->admin->email, 'roles' => ['accountant'], 'password' => null]))))
        ->toBe('users.errors.last_super_admin');

    $other = userWithRole(Role::SuperAdmin);

    expect(staffRuleKey(fn () => app(SetStaffUserActive::class)($this->admin, $this->admin, false)))->toBe('users.errors.cannot_deactivate_self');

    app(SetStaffUserActive::class)($this->admin, $other, false);
    expect(staffRuleKey(fn () => app(UpdateStaffUser::class)($this->admin, $this->admin, staffData(['email' => $this->admin->email, 'roles' => ['accountant'], 'password' => null]))))
        ->toBe('users.errors.last_super_admin');
});

it('locks a deactivated user out of the panel until reactivated', function (): void {
    $cashier = userWithRole(Role::Cashier);
    app(SetStaffUserActive::class)($this->admin, $cashier, false);

    $this->actingAs($cashier->fresh())->get(Dashboard::getUrl())->assertForbidden();

    app(SetStaffUserActive::class)($this->admin, $cashier, true);
    $this->actingAs($cashier->fresh())->get(Dashboard::getUrl())->assertOk();
});

it('creates, edits and deactivates staff from the Users screen', function (): void {
    $this->actingAs($this->admin);
    app()->setLocale('en');

    $this->get(ListUsers::getUrl())->assertOk();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Rina', 'email' => 'rina@somiti.test', 'mobile' => '01812345678', 'locale' => 'en', 'roles' => ['accountant'], 'password' => 'another-long-password'])
        ->callAction(TestAction::make('create')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    $rina = User::query()->where('email', 'rina@somiti.test')->sole();
    expect($rina->hasAnyOf(Role::Accountant))->toBeTrue();

    Livewire::test(EditUser::class, ['record' => $rina->getRouteKey()])
        ->assertSchemaStateSet(['roles' => ['accountant']])
        ->fillForm(['roles' => ['accountant', 'auditor'], 'password' => ''])
        ->callAction(TestAction::make('save')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    expect($rina->fresh()?->hasAnyOf(Role::Auditor))->toBeTrue()
        ->and(Hash::check('another-long-password', (string) $rina->fresh()?->password))->toBeTrue();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deactivate')->table($rina), data: ['confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deactivate')->table($rina), data: ['confirm_text' => 'rina@somiti.test'])
        ->assertHasNoActionErrors();

    expect($rina->fresh()?->isActive())->toBeFalse();
});

it('hides the Users screen from everyone but the super admin', function (): void {
    $this->actingAs(userWithRole(Role::President))->get(ListUsers::getUrl())->assertForbidden();
});
