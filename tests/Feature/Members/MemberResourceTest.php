<?php

declare(strict_types=1);

use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Enums\Role;
use App\Filament\Resources\Members\Actions\MemberActions;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'Asia/Dhaka'));
    approvedPlan('2026-07', '500');
    $this->secretary = userWithRole(Role::Secretary);
    $this->actingAs($this->secretary);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function memberFormAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', 'content');
}

it('adds a member with shares, nominees and a photo', function (): void {
    Storage::fake('local');
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm([
            'name_bn' => 'রহিম উদ্দিন',
            'name_en' => 'Rahim Uddin',
            'mobile' => '01712-345678',
            'nid' => '1234567890123',
            'joined_on' => '2026-10-01',
            'photo_path' => UploadedFile::fake()->image('rahim.jpg', 200, 200),
            'shares' => 2,
            'effective_from' => '2026-10',
            'nominees' => [
                ['name' => 'Karim', 'relation_id' => relationId('son'), 'nid' => '1234567890', 'share_percent' => '50'],
                ['name' => 'Salma', 'relation_id' => relationId('spouse'), 'nid' => '1234567891', 'share_percent' => '50'],
            ],
        ])
        ->assertSee(__('members.nominee.total', ['total' => '100.00%']))
        ->callAction(memberFormAction('create'))
        ->assertHasNoFormErrors();

    $undo();

    $member = Member::query()->sole();

    expect($member->mobile)->toBe('01712345678')
        ->and($member->sharesIn(YearMonth::of(2026, 10)))->toBe(2)
        ->and($member->nominees()->count())->toBe(2)
        ->and($member->photo_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $member->photo_path))->toBeTrue()
        ->and(Due::query()->where('member_id', $member->id)->sole()->amount_poisha->poisha)->toBe(20000);
});

it('rejects an invalid mobile before confirming', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(['name_bn' => 'ক', 'name_en' => 'K', 'mobile' => '12345', 'shares' => 1, 'effective_from' => '2026-10', 'nominees' => [nominee()]])
        ->mountAction(memberFormAction('create'))
        ->assertHasFormErrors(['mobile']);

    $undo();
});

it('asks for at least one nominee with an NID', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(['name_bn' => 'ক', 'name_en' => 'K', 'mobile' => '01812345678', 'shares' => 1, 'effective_from' => '2026-10', 'nominees' => []])
        ->mountAction(memberFormAction('create'))
        ->assertHasFormErrors(['nominees']);

    $undo();
});

it('reports a missing rate plan as a notification', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(['name_bn' => 'ক', 'name_en' => 'K', 'mobile' => '01812345678', 'shares' => 1, 'effective_from' => '2026-06', 'nominees' => [nominee()]])
        ->callAction(memberFormAction('create'))
        ->assertNotified(__('members.errors.no_rate_plan', ['month' => '2026-06']));

    $undo();

    expect(Member::query()->count())->toBe(0);
});

it('edits a member and their nominees', function (): void {
    $member = onboard(1, '2026-07', ['nominees' => [nominee(['name' => 'Karim', 'relation_id' => relationId('son')])]]);
    $undo = Repeater::fake();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->assertSchemaStateSet(['nominees.0.share_percent' => '100.00', 'nominees.0.relation_id' => relationId('son'), 'nominees.0.nid' => '1234567890'])
        ->fillForm([
            'address' => 'Mirpur, Dhaka',
            'nominees' => [['name' => 'Karim', 'relation_id' => relationId('son'), 'nid' => '1234567890', 'share_percent' => '100']],
        ])
        ->callAction(memberFormAction('save'))
        ->assertHasNoFormErrors();

    $undo();

    expect($member->fresh()?->address)->toBe('Mirpur, Dhaka');
});

it('changes shares from the member page with a live summary', function (): void {
    $member = onboard(1, '2026-07');

    expect(MemberActions::changeSummary($member, 'increase', 2, '2026-11'))
        ->toBe('1 → 3 shares from November 2026 · Registration fee due: ৳ 200.00')
        ->and(MemberActions::changeSummary($member, 'decrease', 1, '2026-11'))->toBe('1 → 0 shares from November 2026')
        ->and(MemberActions::changeSummary($member, 'increase', 1, '2026-1'))->toBe('');

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction('changeShares', data: ['type' => 'increase', 'count' => 2, 'from' => '2026-11'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('members.notifications.shares_changed', ['member' => $member->displayName(), 'shares' => '3', 'month' => 'November 2026']));

    expect($member->sharesIn(YearMonth::of(2026, 11)))->toBe(3);
});

it('deactivates only after the member number is typed', function (): void {
    $member = onboard();

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('deactivate')->table($member), data: ['reason' => 'Gone abroad', 'confirm_text' => 'M-0'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('deactivate')->table($member), data: ['reason' => 'Gone abroad', 'confirm_text' => $member->member_no])
        ->assertHasNoActionErrors();

    expect($member->fresh()?->status)->toBe(MemberStatus::Inactive);

    Livewire::test(ListMembers::class)
        ->assertCanNotSeeTableRecords([$member])
        ->filterTable('status', 'inactive')
        ->assertCanSeeTableRecords([$member])
        ->assertActionVisible(TestAction::make('reactivate')->table($member))
        ->assertActionHidden(TestAction::make('changeShares')->table($member));
});

it('lets the cashier look members up but not add them', function (): void {
    $member = onboard(3, '2026-07');
    $this->actingAs(userWithRole(Role::Cashier));

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$member])
        ->assertSee('3')
        ->assertActionHidden('create')
        ->assertActionHidden(TestAction::make('edit')->table($member));
});

it('renders member pages in Bangla', function (): void {
    $member = onboard(2, '2026-07', ['mobile' => '01912345678']);
    app()->setLocale('bn');

    $this->get(MemberResource::getUrl('index'))
        ->assertOk()
        ->assertSee('রহিম উদ্দিন')
        ->assertSee('০১৯১২৩৪৫৬৭৮');

    $this->get(MemberResource::getUrl('view', ['record' => $member]))
        ->assertOk()
        ->assertSee('শেয়ার লট');
});
