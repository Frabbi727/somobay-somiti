<?php

declare(strict_types=1);

use App\Domain\Members\Enums\MaritalStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/*
| Father's name, mother's name, profession and marital status: optional extras beyond the paper
| form, kept on members and registrations and copied across when a registration is approved.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));
    approvedPlan('2026-07', '500');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<string, string>
 */
function familyFields(): array
{
    return [
        'father_name' => 'Abdul Karim',
        'mother_name' => 'Rahima Begum',
        'profession' => 'Teacher',
        'marital_status' => 'married',
    ];
}

it('office create saves the family fields', function (): void {
    $this->actingAs(userWithRole(Role::Secretary));
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(paperCreateState(familyFields()))
        ->callAction(paperFormAction('create'))
        ->assertHasNoFormErrors();

    $undo();

    $member = Member::query()->sole();

    expect($member->father_name)->toBe('Abdul Karim')
        ->and($member->mother_name)->toBe('Rahima Begum')
        ->and($member->profession)->toBe('Teacher')
        ->and($member->marital_status)->toBe(MaritalStatus::Married);
});

it('office create works without them, as on the paper form', function (): void {
    $this->actingAs(userWithRole(Role::Secretary));
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(paperCreateState())
        ->callAction(paperFormAction('create'))
        ->assertHasNoFormErrors();

    $undo();

    $member = Member::query()->sole();

    expect($member->father_name)->toBeNull()
        ->and($member->marital_status)->toBeNull();
});

it('the edit form shows and changes them', function (): void {
    $member = onboard(1, '2026-07', familyFields());
    $this->actingAs(userWithRole(Role::Secretary));
    $undo = Repeater::fake();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->assertSchemaStateSet(['father_name' => 'Abdul Karim', 'marital_status' => MaritalStatus::Married])
        ->fillForm(['profession' => 'Farmer', 'marital_status' => 'widowed'])
        ->callAction(paperFormAction('save'))
        ->assertHasNoFormErrors();

    $undo();

    expect($member->fresh()?->profession)->toBe('Farmer')
        ->and($member->fresh()?->marital_status)->toBe(MaritalStatus::Widowed);
});

it('refuses a name or profession that is too long', function (string $field): void {
    try {
        onboard(1, '2026-07', [$field => str_repeat('a', 256)]);
        $key = null;
    } catch (DomainRuleViolation $violation) {
        $key = $violation->translationKey;
    }

    expect($key)->toBe('members.errors.field_too_long');
})->with(['father_name', 'mother_name', 'profession']);

it('the app saves and returns them on the registration', function (): void {
    userWithRole(Role::Secretary);
    $application = invite('01811111111');
    $token = app(MemberTokens::class)->issue($application->user)['access_token'];

    $this->withToken($token)->putJson('/api/v1/registration', registrationInput(familyFields()))
        ->assertOk()
        ->assertJsonPath('data.data.father_name', 'Abdul Karim')
        ->assertJsonPath('data.data.mother_name', 'Rahima Begum')
        ->assertJsonPath('data.data.profession', 'Teacher')
        ->assertJsonPath('data.data.marital_status.value', 'married');
});

it('the app names a wrong marital status or a too-long name', function (): void {
    userWithRole(Role::Secretary);
    $application = invite('01811111111');
    $token = app(MemberTokens::class)->issue($application->user)['access_token'];

    $this->withToken($token)->putJson('/api/v1/registration', ['marital_status' => 'complicated', 'father_name' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['marital_status', 'father_name'], 'errors');
});

it('approving a registration copies them to the member', function (): void {
    userWithRole(Role::Secretary);
    $application = invite('01811111111');
    completeRegistration($application, familyFields());
    $approved = approveRegistration(submittedRegistrationFrom($application->fresh()));

    $member = Member::query()->whereKey($approved->member_id)->sole();

    expect($member->father_name)->toBe('Abdul Karim')
        ->and($member->mother_name)->toBe('Rahima Begum')
        ->and($member->profession)->toBe('Teacher')
        ->and($member->marital_status)->toBe(MaritalStatus::Married);
});
