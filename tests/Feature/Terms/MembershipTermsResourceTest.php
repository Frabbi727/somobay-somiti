<?php

declare(strict_types=1);

use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Enums\TermsStatus;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Settings\Actions\UpdateRolePermissions;
use App\Domain\Settings\Services\RolePermissions;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages\EditMembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages\ListMembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages\ViewMembershipTerms;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
});

it('president drafts and publishes a new version', function (): void {
    $this->actingAs($president = userWithRole(Role::President));
    $current = MembershipTerms::current();

    Livewire::test(ListMembershipTerms::class)
        ->callAction('newDraft')
        ->assertNotified(__('terms.admin.draft_created'));

    $draft = MembershipTerms::draft();
    expect($draft)->not->toBeNull()
        ->and($draft->rules_bn)->toBe($current->rules_bn);

    Livewire::test(EditMembershipTerms::class, ['record' => $draft->getKey()])
        ->fillForm(['rules_en' => 'Revised rule text'])
        ->callAction(TestAction::make('save')->schemaComponent('form-actions', 'content'))
        ->assertHasNoFormErrors();

    expect($draft->refresh()->rules_en)->toBe('Revised rule text');

    Livewire::test(ListMembershipTerms::class)->assertActionHidden('newDraft');

    Livewire::test(ViewMembershipTerms::class, ['record' => $draft->getKey()])
        ->callAction('publish', ['confirm_text' => '1'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(ViewMembershipTerms::class, ['record' => $draft->getKey()])
        ->callAction('publish', ['confirm_text' => '2'])
        ->assertHasNoActionErrors();

    expect($draft->refresh()->status)->toBe(TermsStatus::Published)
        ->and($draft->version)->toBe(2)
        ->and($draft->published_by)->toBe($president->id);
});

it('cashier cannot see publish', function (): void {
    $draft = app(SaveTermsDraft::class)(userWithRole(Role::President), 'ক', 'a', 'খ', 'b');
    $this->actingAs(userWithRole(Role::Cashier));

    Livewire::test(ListMembershipTerms::class)
        ->assertSuccessful()
        ->assertActionHidden('newDraft')
        ->assertTableActionHidden('publish', $draft);

    Livewire::test(ViewMembershipTerms::class, ['record' => $draft->getKey()])
        ->assertActionHidden('publish');

    Livewire::test(EditMembershipTerms::class, ['record' => $draft->getKey()])->assertForbidden();
});

it('published version is read-only in the UI', function (): void {
    $this->actingAs(userWithRole(Role::President));
    $current = MembershipTerms::current();

    Livewire::test(ListMembershipTerms::class)
        ->assertCanSeeTableRecords([$current])
        ->assertTableActionHidden('edit', $current)
        ->assertTableActionHidden('publish', $current);

    Livewire::test(ViewMembershipTerms::class, ['record' => $current->getKey()])
        ->assertActionHidden('edit')
        ->assertActionHidden('publish');

    Livewire::test(EditMembershipTerms::class, ['record' => $current->getKey()])->assertForbidden();
});

it('keeps the rules list from staff without members access', function (): void {
    $president = userWithRole(Role::President);
    $secretary = userWithRole(Role::Secretary);
    $grid = app(RolePermissions::class)->grid();
    $grid[Permission::ViewMembers->value][Role::Secretary->value] = false;
    app(UpdateRolePermissions::class)($president, $grid);

    $this->actingAs($secretary->fresh());

    Livewire::test(ListMembershipTerms::class)->assertForbidden();
});
