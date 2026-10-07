<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Actions\UpdateRegistrationApprovalChain;
use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\RegistrationApprovalsPage;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    SomitiProfile::query()->create(['id' => SomitiProfile::ID, 'name_bn' => 'সমিতি', 'name_en' => 'Somiti']);
});

it('changes the order for new submissions and keeps it for submitted ones', function (): void {
    $before = submittedRegistration('01811111111');

    app(UpdateRegistrationApprovalChain::class)(userWithRole(Role::President), [Role::Secretary, Role::Cashier, Role::President]);
    $after = app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), (string) Str::uuid());

    expect($before->fresh()?->approval_chain)->toBe(['secretary', 'president'])
        ->and($after->approval_chain)->toBe(['secretary', 'cashier', 'president']);
});

it('refuses an empty, repeated or non-committee order', function (array $roles): void {
    expect(memberRuleKey(fn () => app(UpdateRegistrationApprovalChain::class)(userWithRole(Role::President), $roles)))->toBe('registration.errors.chain_invalid');
})->with([
    'empty' => [[]],
    'repeated' => [[Role::Secretary, Role::Secretary]],
    'auditor' => [[Role::Auditor]],
    'member' => [[Role::Member]],
]);

it('saves the order from the settings page', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::President));

    Livewire::test(RegistrationApprovalsPage::class)
        ->fillForm(['steps' => [['role' => 'president']]])
        ->callAction('save')
        ->assertHasNoActionErrors();

    expect(SomitiProfile::current()->registrationApprovalChain())->toBe([Role::President]);
});

it('refuses an order that ends with a role who cannot add members', function (): void {
    expect(memberRuleKey(fn () => app(UpdateRegistrationApprovalChain::class)(userWithRole(Role::President), [Role::Secretary, Role::Cashier])))
        ->toBe('registration.errors.chain_last_cannot_create');
});
