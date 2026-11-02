<?php

declare(strict_types=1);

use App\Domain\Geo\Models\GeoUpazila;
use App\Domain\Members\Actions\UpdateMember;
use App\Domain\Members\Enums\Gender;
use App\Domain\Members\Enums\RegistrationDocument;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Actions\RecordApplicationDocuments;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Services\MemberRules;
use App\Enums\Role;
use App\Support\Time\YearMonth;

dataset('paper form violations', [
    'no gender' => [['gender' => null], 'members.errors.gender_required'],
    'no present address' => [['present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null], 'members.errors.address_required'],
    'no permanent address' => [['permanent_upazila_id' => null, 'permanent_union_id' => null, 'permanent_address_line' => null], 'members.errors.address_required'],
    'no address line' => [['present_address_line' => ''], 'members.errors.address_required'],
    'union outside the upazila' => [['present_upazila_id' => 365, 'present_union_id' => 4541], 'members.errors.address_union_mismatch'],
    'union missing where the upazila has unions' => [['present_union_id' => null], 'members.errors.address_union_required'],
    'long address line' => [['permanent_address_line' => str_repeat('a', 256)], 'members.errors.address_line_too_long'],
    'bad passport' => [['passport_no' => 'ab-1'], 'members.errors.passport_format'],
    'nominee without gender' => [['nominees' => [['gender' => null]]], 'members.errors.nominee_gender_required'],
    'nominee without address' => [['nominees' => [['present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null]]], 'members.errors.address_required'],
    'nominee union outside the upazila' => [['nominees' => [['permanent_upazila_id' => 365]]], 'members.errors.address_union_mismatch'],
]);

it('runs the paper form rules for a new member', function (array $overrides, string $key): void {
    if (isset($overrides['nominees'])) {
        $overrides['nominees'] = [nominee($overrides['nominees'][0])];
    }

    expect(memberRuleKey(fn () => app(MemberRules::class)->assertValid(memberData($overrides))))->toBe($key);
})->with('paper form violations');

it('accepts an address without union when the upazila has none', function (): void {
    $guimara = GeoUpazila::query()->where('name_en', 'Guimara')->firstOrFail();

    $data = memberData([
        'present_upazila_id' => $guimara->id,
        'present_union_id' => null,
        'permanent_same_as_present' => true,
    ]);

    app(MemberRules::class)->assertValid($data);

    expect($data->presentAddress?->unionId)->toBeNull();
});

it('copies the present address when permanent is the same', function (): void {
    $data = memberData(['permanent_upazila_id' => null, 'permanent_union_id' => null, 'permanent_address_line' => null, 'permanent_same_as_present' => true]);

    expect($data->permanentAddress)->toEqual($data->presentAddress)
        ->and($data->presentAddress?->upazilaId)->toBe(495);
});

it('normalises the passport number', function (): void {
    expect(memberData(['passport_no' => ' ab 123456 '])->passportNo)->toBe('AB123456');
});

it('does not demand the new fields when updating a legacy member', function (): void {
    $member = Member::factory()->create();

    app(UpdateMember::class)(userWithRole(Role::Secretary), $member, memberData([
        'mobile' => $member->mobile,
        'gender' => null,
        'present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null,
        'permanent_upazila_id' => null, 'permanent_union_id' => null, 'permanent_address_line' => null,
        'nominees' => [nominee(['gender' => null, 'present_upazila_id' => null, 'present_union_id' => null, 'present_address_line' => null, 'permanent_upazila_id' => null, 'permanent_union_id' => null, 'permanent_address_line' => null])],
    ]));

    expect($member->fresh()?->gender)->toBeNull();
});

it('copies the new fields to the member and nominees on approval', function (): void {
    approvedPlan('2026-07', '500');
    $application = submittedRegistration();
    $secretary = userWithRole(Role::Secretary);
    $president = userWithRole(Role::President);
    app(RecordApplicationDocuments::class)($secretary, $application, [RegistrationDocument::Nid, RegistrationDocument::NomineePhoto]);

    $application = app(DecideRegistration::class)($secretary, $application->refresh(), RegistrationDecisionType::Approve);
    app(DecideRegistration::class)($president, $application, RegistrationDecisionType::Approve, null, 2, YearMonth::parse('2026-07'));

    $member = Member::query()->sole();
    $nominee = $member->nominees()->sole();

    expect($member->gender)->toBe(Gender::Male)
        ->and($member->documents_received->all())->toBe([RegistrationDocument::Nid, RegistrationDocument::NomineePhoto])
        ->and($member->present_upazila_id)->toBe(495)
        ->and($member->present_union_id)->toBe(4541)
        ->and($member->permanent_address_line)->toBe('House 12, Road 3')
        ->and($nominee->gender)->toBe(Gender::Female)
        ->and($nominee->present_upazila_id)->toBe(495)
        ->and($nominee->permanent_union_id)->toBe(4541);
});
