<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Settings\Actions\UpdateSomitiProfile;
use App\Domain\Settings\Data\SomitiProfileData;
use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\SomitiProfilePage;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The society's own name, registration, address and logo, printed on receipts, reports and both panels.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->admin = userWithRole(Role::SuperAdmin);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function somitiData(array $overrides = []): SomitiProfileData
{
    return SomitiProfileData::fromForm([
        'name_bn' => 'সবুজ সমবায় সমিতি',
        'name_en' => 'Sabuj Cooperative Society',
        'registration_no' => 'DHK-1234',
        'registered_on' => '2020-07-01',
        'address_bn' => 'ঢাকা',
        'address_en' => 'Dhaka',
        'phone' => '01712345678',
        'email' => 'office@sabuj.test',
        ...$overrides,
    ]);
}

function somitiRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('falls back to the application name until the profile is filled in', function (): void {
    expect(SomitiProfile::current()->exists)->toBeFalse()
        ->and(SomitiProfile::current()->displayName())->toBe((string) config('app.name'));
});

it('saves the one profile row, logs the change and shows the name in the right language', function (): void {
    app(UpdateSomitiProfile::class)($this->admin, somitiData());
    $profile = app(UpdateSomitiProfile::class)($this->admin, somitiData(['name_en' => 'Sabuj Society', 'address_en' => '']));

    expect(SomitiProfile::query()->count())->toBe(1)
        ->and($profile->id)->toBe(SomitiProfile::ID)
        ->and($profile->registered_on?->toDateString())->toBe('2020-07-01')
        ->and($profile->updated_by)->toBe($this->admin->id)
        ->and(SomitiProfile::current()->displayName('bn'))->toBe('সবুজ সমবায় সমিতি')
        ->and(SomitiProfile::current()->displayName('en'))->toBe('Sabuj Society')
        ->and(SomitiProfile::current()->displayAddress('en'))->toBe('ঢাকা');

    $entry = AuditEntry::query()->where('log_name', 'settings')->where('subject_type', (new SomitiProfile)->getMorphClass())->latest('id')->first();
    expect($entry?->causer_id)->toBe($this->admin->id)
        ->and($entry?->attribute_changes?->get('attributes'))->toMatchArray(['name_en' => 'Sabuj Society']);
});

it('rejects missing names, a bad phone or a bad email', function (array $overrides, string $key): void {
    expect(somitiRuleKey(fn () => app(UpdateSomitiProfile::class)($this->admin, somitiData($overrides))))->toBe($key)
        ->and(SomitiProfile::query()->exists())->toBeFalse();
})->with([
    'no Bangla name' => [['name_bn' => '  '], 'somiti.errors.names_required'],
    'no English name' => [['name_en' => ''], 'somiti.errors.names_required'],
    'phone' => [['phone' => 'call us'], 'somiti.errors.phone_invalid'],
    'email' => [['email' => 'not-an-email'], 'somiti.errors.email_invalid'],
]);

it('accepts a landline as the phone', function (): void {
    expect(app(UpdateSomitiProfile::class)($this->admin, somitiData(['phone' => '02-9551234']))->phone)->toBe('02-9551234');
});

it('lets only the super admin and president edit the profile', function (Role $role, bool $allowed): void {
    $user = userWithRole($role);
    $this->actingAs($user)->get(SomitiProfilePage::getUrl())->assertStatus($allowed ? 200 : 403);

    if (! $allowed) {
        expect(fn () => app(UpdateSomitiProfile::class)($user, somitiData()))->toThrow(AuthorizationException::class);
    }
})->with([[Role::SuperAdmin, true], [Role::President, true], [Role::Accountant, false], [Role::Auditor, false]]);

it('can never be deleted and holds only one row', function (): void {
    $profile = app(UpdateSomitiProfile::class)($this->admin, somitiData());

    expect($this->admin->can('delete', $profile))->toBeFalse()
        ->and($this->admin->can('create', SomitiProfile::class))->toBeFalse()
        ->and(fn () => SomitiProfile::query()->insert(['id' => 2, 'name_bn' => 'x', 'name_en' => 'x']))->toThrow(QueryException::class);
});

it('deletes the old logo only after a new one is saved', function (): void {
    Storage::fake(SomitiProfile::LOGO_DISK);
    Storage::disk(SomitiProfile::LOGO_DISK)->put('somiti/old.png', 'old');
    Storage::disk(SomitiProfile::LOGO_DISK)->put('somiti/new.png', 'new');

    app(UpdateSomitiProfile::class)($this->admin, somitiData(['logo_path' => 'somiti/old.png']));
    expect(SomitiProfile::current()->logoDataUri())->toStartWith('data:');

    app(UpdateSomitiProfile::class)($this->admin, somitiData(['logo_path' => 'somiti/new.png']));
    Storage::disk(SomitiProfile::LOGO_DISK)->assertMissing('somiti/old.png');
    Storage::disk(SomitiProfile::LOGO_DISK)->assertExists('somiti/new.png');
});

it('saves from the Society profile page after a summary confirmation', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(SomitiProfilePage::class)
        ->fillForm(['name_bn' => 'সবুজ সমবায় সমিতি', 'name_en' => 'Sabuj Cooperative Society', 'registration_no' => 'DHK-1234'])
        ->callAction('save')
        ->assertHasNoActionErrors()
        ->assertNotified(__('somiti.saved', [], 'bn'));

    expect(SomitiProfile::query()->sole()->registration_no)->toBe('DHK-1234');
});
