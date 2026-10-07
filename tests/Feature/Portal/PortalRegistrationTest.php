<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Enums\Role;
use App\Filament\Member\Pages\Auth\MemberLogin;
use App\Filament\Member\Pages\Registration;
use App\Filament\Member\Pages\RegistrationStatus;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('member');
    app()->setLocale('en');
    approvedPlan('2026-07', '500');
    $this->application = invite('01811111111', 'secret-123');
});

it('signs an invited member in to the portal', function (): void {
    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01811111111', 'password' => 'secret-123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($this->application->user);
});

it('keeps an applicant on the registration pages', function (): void {
    $this->application->user->forceFill(['locale' => 'en'])->save();
    $this->actingAs($this->application->user);

    $this->get('/portal')->assertRedirect(RegistrationStatus::getUrl());
    $this->get('/portal/dues')->assertRedirect(RegistrationStatus::getUrl());
    $this->get(RegistrationStatus::getUrl())->assertOk()->assertSee('Your registration is incomplete');
    $this->get(Registration::getUrl())->assertOk();
});

it('saves each wizard step and submits after the summary', function (): void {
    userWithRole(Role::Secretary);
    $this->actingAs($this->application->user);

    Livewire::test(Registration::class)
        ->fillForm(['name_bn' => 'করিম মিয়া', 'name_en' => 'Karim Mia'])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors();

    expect($this->application->fresh()?->name_en)->toBe('Karim Mia');

    Livewire::test(Registration::class)
        ->fillForm([...registrationInput(), 'nominees' => [nominee()]])
        ->callAction('submit')
        ->assertHasNoActionErrors()
        ->assertRedirect(RegistrationStatus::getUrl());

    expect($this->application->fresh()?->status)->toBe(MemberApplicationStatus::Submitted);
});

it('stores a photo uploaded in the form when the registration is submitted', function (): void {
    Storage::fake('local');
    $this->actingAs($this->application->user);

    Livewire::test(Registration::class)
        ->fillForm([...registrationInput(), 'photo_path' => UploadedFile::fake()->image('me.jpg')])
        ->callAction('submit')
        ->assertHasNoActionErrors();

    $path = $this->application->fresh()?->photo_path;

    expect($path)->toStartWith('member-photos/');
    Storage::disk('local')->assertExists((string) $path);
});

it('never saves a photo path typed into the form state', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('member-photos/mine.jpg', 'mine');
    Storage::disk('local')->put('member-photos/someone-else.jpg', 'theirs');
    app(SaveRegistrationDraft::class)($this->application, RegistrationDraft::fromInput(['photo_path' => 'member-photos/mine.jpg']));
    $this->actingAs($this->application->user);

    Livewire::test(Registration::class)
        ->fillForm(['name_bn' => 'করিম মিয়া', 'name_en' => 'Karim Mia'])
        ->set('data.photo_path', ['tampered' => 'member-photos/someone-else.jpg'])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors();

    expect($this->application->fresh()?->photo_path)->toBe('member-photos/mine.jpg');

    Livewire::test(Registration::class)
        ->fillForm(registrationInput())
        ->set('data.photo_path', ['tampered' => 'member-photos/someone-else.jpg'])
        ->callAction('submit');

    expect($this->application->fresh()?->photo_path)->toBe('member-photos/mine.jpg');
    Storage::disk('local')->assertExists('member-photos/someone-else.jpg');
});

it('sends a member whose registration is under review back to the status page', function (): void {
    submittedRegistrationFrom($this->application);
    $this->actingAs($this->application->user);

    Livewire::test(Registration::class)->assertRedirect(RegistrationStatus::getUrl());
});

it('lets an approved member into the normal portal and out of the registration pages', function (): void {
    approveRegistration(submittedRegistrationFrom($this->application));
    $this->actingAs($this->application->user->fresh());

    $this->get('/portal')->assertOk();
    $this->get(RegistrationStatus::getUrl())->assertForbidden();
    $this->get(Registration::getUrl())->assertForbidden();
});
