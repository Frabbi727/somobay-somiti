<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;

it('saves part of the registration and keeps the rest', function (): void {
    $application = invite();
    $save = app(SaveRegistrationDraft::class);

    $save($application, RegistrationDraft::fromInput(['name_bn' => 'করিম মিয়া', 'nid' => '৯৮৭৬৫৪৩২১০']));
    $saved = $save($application, RegistrationDraft::fromInput(['name_en' => 'Karim Mia']));

    expect($saved->name_bn)->toBe('করিম মিয়া')
        ->and($saved->name_en)->toBe('Karim Mia')
        ->and($saved->nid)->toBe('9876543210')
        ->and($saved->status)->toBe(MemberApplicationStatus::Invited);
});

it('replaces the nominee list only when nominees are sent', function (): void {
    $application = completeRegistration(invite(), ['nominees' => [
        nominee(['name' => 'A', 'share_percent' => '50']),
        nominee(['name' => 'B', 'share_percent' => '50']),
    ]]);

    app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['address' => 'Uttara']));
    expect($application->nominees()->pluck('name')->all())->toBe(['A', 'B']);

    app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['nominees' => [nominee(['name' => 'C'])]]));
    expect($application->nominees()->pluck('name')->all())->toBe(['C'])
        ->and($application->nominees()->first()?->share_bps)->toBe(10000);
});

it('keeps a nominee row without a share yet as 0%', function (): void {
    $application = completeRegistration(invite(), ['nominees' => [nominee(['share_percent' => ''])]]);

    expect($application->nominees()->first()?->share_bps)->toBe(0);
});

it('refuses changes after the registration is submitted', function (): void {
    $application = invite();
    $application->forceFill(['status' => MemberApplicationStatus::Submitted])->save();

    expect(memberRuleKey(fn () => app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['name_bn' => 'x']))))
        ->toBe('registration.errors.not_editable');
});

it('refuses an NID the database would refuse', function (): void {
    expect(memberRuleKey(fn () => app(SaveRegistrationDraft::class)(invite(), RegistrationDraft::fromInput(['nid' => '123']))))
        ->toBe('members.errors.nid_format');
});

dataset('invalid drafts', [
    'impossible date' => [['date_of_birth' => '2020-13-45'], 'registration.errors.date_of_birth_invalid'],
    'future date' => [['date_of_birth' => '2999-01-01'], 'registration.errors.date_of_birth_invalid'],
    'bad email' => [['email' => 'not-an-email'], 'registration.errors.email_invalid'],
    'long name' => [['name_en' => str_repeat('a', 256)], 'registration.errors.field_too_long'],
    'nominee nid' => [['nominees' => [['name' => 'Karima', 'nid' => '123', 'share_percent' => '100']]], 'members.errors.nominee_nid_format'],
    'nominee mobile' => [['nominees' => [['name' => 'Karima', 'mobile' => '0123', 'share_percent' => '100']]], 'members.errors.nominee_mobile_format'],
    'unknown relation' => [['nominees' => [['name' => 'Karima', 'relation_id' => 99999, 'share_percent' => '100']]], 'members.errors.nominee_relation'],
    'fractional shares' => [['requested_shares' => '1.5'], 'registration.errors.shares_required'],
    'too many shares' => [['requested_shares' => 1001], 'registration.errors.shares_required'],
    'photo outside folder' => [['photo_path' => '../.env'], 'registration.errors.photo_invalid'],
]);

it('refuses a badly formatted field and leaves the application unchanged', function (array $input, string $key): void {
    $application = completeRegistration(invite());
    $before = $application->fresh()->getAttributes();

    expect(memberRuleKey(fn () => app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput($input))))->toBe($key);

    $after = $application->fresh();
    expect($after->getAttributes())->toBe($before)
        ->and($after->nominees()->pluck('name')->all())->toBe(['Karima']);
})->with('invalid drafts');
