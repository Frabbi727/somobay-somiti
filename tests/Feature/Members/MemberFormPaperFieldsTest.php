<?php

declare(strict_types=1);

use App\Domain\Geo\Services\GeoDirectory;
use App\Domain\Members\Enums\Gender;
use App\Domain\Members\Enums\RegistrationDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Actions\RecordApplicationDocuments;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ViewMember;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));
    approvedPlan('2026-07', '500');
    $this->actingAs(userWithRole(Role::Secretary));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('office create saves gender, addresses, nominee addresses and documents', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(paperCreateState())
        ->callAction(paperFormAction('create'))
        ->assertHasNoFormErrors();

    $undo();

    $member = Member::query()->with('nominees')->sole();
    $nominee = $member->nominees->sole();

    expect($member->gender)->toBe(Gender::Male)
        ->and($member->passport_no)->toBe('AB123456')
        ->and($member->present_upazila_id)->toBe(495)
        ->and($member->present_union_id)->toBe(4541)
        ->and($member->present_address_line)->toBe('House 12, Road 3')
        ->and($member->permanent_upazila_id)->toBe(495)
        ->and($member->permanent_address_line)->toBe('House 12, Road 3')
        ->and(array_map(fn (RegistrationDocument $d): string => $d->value, $member->documents_received->all()))->toBe(['nid', 'nominee_photo'])
        ->and($nominee->gender)->toBe(Gender::Female)
        ->and($nominee->present_upazila_id)->toBe(495)
        ->and($nominee->permanent_union_id)->toBe(4541)
        ->and(TermsAcceptance::query()->where('member_id', $member->id)->sole()->channel)->toBe(ConsentChannel::Office);
});

it('office create needs the rules confirmation', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(paperCreateState(['terms_accepted' => false]))
        ->mountAction(paperFormAction('create'))
        ->assertHasFormErrors(['terms_accepted']);

    $undo();

    expect(Member::query()->count())->toBe(0);
});

it('office create needs gender and the present address', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(paperCreateState(['gender' => null, 'present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => '']))
        ->mountAction(paperFormAction('create'))
        ->assertHasFormErrors(['gender', 'present_upazila_id', 'present_address_line']);

    $undo();
});

it('does not demand a union when the upazila has none', function (): void {
    $upazilaId = (int) DB::table('geo_upazilas')->whereNotIn('id', DB::table('geo_unions')->select('upazila_id'))->value('id');
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(paperCreateState(['present_upazila_id' => $upazilaId, 'present_union_id' => null]))
        ->callAction(paperFormAction('create'))
        ->assertHasNoFormErrors();

    $undo();

    expect($upazilaId)->toBeGreaterThan(0)
        ->and(Member::query()->sole()->present_union_id)->toBeNull();
});

it('clears upazila and union when district changes', function (): void {
    $chain = app(GeoDirectory::class)->chainFor(495);
    $otherDistrict = (int) DB::table('geo_districts')->where('division_id', $chain['division_id'])->where('id', '!=', $chain['district_id'])->value('id');

    $page = Livewire::test(CreateMember::class)
        ->set('data.present_division_id', $chain['division_id'])
        ->set('data.present_district_id', $chain['district_id'])
        ->set('data.present_upazila_id', 495)
        ->set('data.present_union_id', 4541)
        ->assertSet('data.present_union_id', 4541)
        ->set('data.present_district_id', $otherDistrict)
        ->assertSet('data.present_upazila_id', null)
        ->assertSet('data.present_union_id', null);

    $page->set('data.present_division_id', null)->assertSet('data.present_district_id', null);
});

it('edit form shows division and district for a saved upazila', function (): void {
    $member = onboard();
    $chain = app(GeoDirectory::class)->chainFor(495);

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->assertSchemaStateSet([
            'present_division_id' => $chain['division_id'],
            'present_district_id' => $chain['district_id'],
            'present_upazila_id' => 495,
            'permanent_division_id' => $chain['division_id'],
            'permanent_district_id' => $chain['district_id'],
        ]);
});

function upazilaWithoutUnions(): int
{
    return (int) DB::table('geo_upazilas')->whereNotIn('id', DB::table('geo_unions')->select('upazila_id'))->value('id');
}

it('editing a member keeps gender, addresses, nominee addresses and documents', function (): void {
    $member = onboard(1, '2026-07', [
        'passport_no' => 'AB123456',
        'documents_received' => ['nid', 'passport'],
        'present_address_line' => 'Flat 4',
        'permanent_upazila_id' => upazilaWithoutUnions(),
        'permanent_union_id' => null,
        'permanent_address_line' => 'Village Road',
        'nominees' => [nominee(['name' => 'Karim', 'gender' => 'male', 'passport_no' => 'ZX9912', 'permanent_upazila_id' => upazilaWithoutUnions(), 'permanent_union_id' => null, 'permanent_address_line' => 'Nominee Village'])],
    ]);
    $before = $member->fresh()->load('nominees');
    $undo = Repeater::fake();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->fillForm(['name_en' => 'Rahim U. Changed'])
        ->callAction(paperFormAction('save'))
        ->assertHasNoFormErrors();

    $undo();

    $after = $member->fresh()->load('nominees');
    $keys = ['gender', 'passport_no', 'present_upazila_id', 'present_union_id', 'present_address_line', 'permanent_upazila_id', 'permanent_union_id', 'permanent_address_line'];

    expect($after->name_en)->toBe('Rahim U. Changed')
        ->and($after->only($keys))->toEqual($before->only($keys))
        ->and($after->documents_received->all())->toEqual($before->documents_received->all())
        ->and($after->nominees->sole()->only($keys))->toEqual($before->nominees->sole()->only($keys));
});

it('editing a legacy member does not demand the new fields', function (): void {
    $member = onboard();
    DB::table('members')->where('id', $member->id)->update([
        'gender' => null, 'passport_no' => null,
        'present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null,
        'permanent_upazila_id' => null, 'permanent_union_id' => null, 'permanent_address_line' => null,
        'address' => 'Old village',
    ]);
    DB::table('nominees')->where('member_id', $member->id)->update([
        'gender' => null, 'present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null,
        'permanent_upazila_id' => null, 'permanent_union_id' => null, 'permanent_address_line' => null,
    ]);
    $undo = Repeater::fake();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->fillForm(['name_en' => 'Legacy Edited'])
        ->callAction(paperFormAction('save'))
        ->assertHasNoFormErrors();

    $undo();

    $fresh = $member->fresh();

    expect($fresh?->name_en)->toBe('Legacy Edited')
        ->and($fresh?->address)->toBe('Old village')
        ->and($fresh?->present_upazila_id)->toBeNull();
});

it('shows the old address only when there is no new present address', function (): void {
    $member = onboard();

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertSee('House 12, Road 3')
        ->assertDontSee('Old address');

    DB::table('members')->where('id', $member->id)->update(['present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null, 'address' => 'Old village']);

    Livewire::test(ViewMember::class, ['record' => $member->fresh()->getRouteKey()])
        ->assertSee('Old address')
        ->assertSee('Old village');
});

it('approver records documents received on an application', function (): void {
    $application = submittedRegistration();

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->callAction('recordDocuments', ['documents' => ['nid', 'nominee_nid']])->assertNotified(__('registration.notifications.documents_recorded'))
        ->assertHasNoActionErrors();

    expect(array_map(fn (RegistrationDocument $d): string => $d->value, $application->fresh()->documents_received->all()))->toBe(['nid', 'nominee_nid']);
});

it('refuses to record documents on a closed application or without permission', function (): void {
    $application = approveRegistration(submittedRegistration());
    $open = invite('01899999999');

    expect($application->status)->toBe(MemberApplicationStatus::Approved)
        ->and(fn () => app(RecordApplicationDocuments::class)(userWithRole(Role::Secretary), $application, [RegistrationDocument::Nid]))
        ->toThrow(DomainRuleViolation::class);

    expect(fn () => app(RecordApplicationDocuments::class)(userWithRole(Role::Member), $open, [RegistrationDocument::Nid]))
        ->toThrow(AuthorizationException::class);
});
