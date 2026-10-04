<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Actions\UpdateRolePermissions;
use App\Domain\Settings\Services\RolePermissions;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\RolePermissionsPage;
use App\Filament\Resources\Members\MemberResource;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
| The president decides which role holds which permission; the locked rules hold whatever is ticked.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->president = userWithRole(Role::President);
});

/**
 * The stored grid with one box changed.
 *
 * @return array<string, array<string, bool>>
 */
function gridWith(Permission $permission, Role $role, bool $granted): array
{
    $grid = app(RolePermissions::class)->grid();
    $grid[$permission->value][$role->value] = $granted;

    return $grid;
}

it('gives every role exactly its default permissions to start with', function (): void {
    $grid = app(RolePermissions::class)->grid();

    foreach (Permission::cases() as $permission) {
        foreach (Permission::editableRoles() as $role) {
            expect($grid[$permission->value][$role->value])
                ->toBe(in_array($role, $permission->defaultRoles(), true) && ! $permission->isLockedFor($role), "{$permission->value} / {$role->value}");
        }
    }
});

it('lets the president give the cashier the right to add members, which then works at once', function (): void {
    $cashier = userWithRole(Role::Cashier);
    expect($cashier->can('create', Member::class))->toBeFalse();

    $changes = app(UpdateRolePermissions::class)($this->president, gridWith(Permission::MembersCreate, Role::Cashier, true));

    expect($changes)->toHaveCount(1)
        ->and($cashier->fresh()?->can('create', Member::class))->toBeTrue();

    $this->actingAs($cashier->fresh())->get(MemberResource::getUrl('create'))->assertOk();
});

it('hides a menu when its view permission is taken away', function (): void {
    $secretary = userWithRole(Role::Secretary);
    $this->actingAs($secretary)->get(MemberResource::getUrl())->assertOk();

    app(UpdateRolePermissions::class)($this->president, gridWith(Permission::ViewMembers, Role::Secretary, false));

    $this->actingAs($secretary->fresh())->get(MemberResource::getUrl())->assertForbidden();
});

it('keeps the locked rules whatever is ticked', function (): void {
    expect(fn () => app(UpdateRolePermissions::class)($this->president, gridWith(Permission::PaymentsApprove, Role::Auditor, true)))
        ->toThrow(DomainRuleViolation::class);

    $grid = app(RolePermissions::class)->grid();
    foreach (Permission::editableRoles() as $role) {
        $grid[Permission::PaymentsApprove->value][$role->value] = false;
    }
    expect(fn () => app(UpdateRolePermissions::class)($this->president, $grid))->toThrow(DomainRuleViolation::class);

    expect(fn () => app(UpdateRolePermissions::class)($this->president, app(RolePermissions::class)->grid()))
        ->toThrow(DomainRuleViolation::class);

    // Maker-checker stays: a cashier given "approve" still cannot approve a payment they recorded.
    app(UpdateRolePermissions::class)($this->president, gridWith(Permission::PaymentsApprove, Role::Cashier, true));
    travelTo('2026-07-05');
    approvedPlan('2026-07', '500');
    $member = onboard(1, '2026-07');
    $cashier = userWithRole(Role::Cashier);
    $payment = app(RecordPayment::class)($cashier, PaymentData::fromForm([
        'member_id' => $member->id, 'method' => 'cash', 'amount' => Money::ofTaka('520'), 'received_on' => '2026-07-05',
    ]));

    expect($cashier->can('approve', $payment))->toBeFalse()
        ->and(userWithRole(Role::Cashier)->can('approve', $payment))->toBeTrue();
});

it('lets only the president change permissions', function (Role $role): void {
    app(UpdateRolePermissions::class)(userWithRole($role), gridWith(Permission::MembersCreate, Role::Cashier, true));
})->with([Role::SuperAdmin, Role::Accountant, Role::Secretary, Role::Auditor])->throws(AuthorizationException::class);

it('saves from the grid with a typed confirmation and logs the change', function (): void {
    $this->actingAs($this->president);

    Livewire::test(RolePermissionsPage::class)
        ->set('grid.MembersCreate.cashier', true)
        ->set('grid.DuesWaive.secretary', true)
        ->callAction('savePermissions', data: ['confirm_text' => '2'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $grid = app(RolePermissions::class)->grid();
    $entry = AuditEntry::query()->where('log_name', 'permissions')->sole();

    expect($grid['members.create']['cashier'])->toBeTrue()
        ->and($grid['dues.waive']['secretary'])->toBeTrue()
        ->and($entry->causer_id)->toBe($this->president->id)
        ->and($entry->properties['changes'])->toHaveCount(2);
});

it('shows the grid read-only to the super admin and auditor and hides it from others', function (): void {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    Livewire::test(RolePermissionsPage::class)->assertActionHidden('savePermissions')->assertSee(__('permissions.read_only'));

    $this->actingAs(userWithRole(Role::Cashier))->get(RolePermissionsPage::getUrl())->assertForbidden();
});
