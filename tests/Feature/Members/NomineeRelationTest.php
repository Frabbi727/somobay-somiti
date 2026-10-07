<?php

declare(strict_types=1);

use App\Domain\Members\Actions\SaveNomineeRelation;
use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\NomineeRelations\Pages\ManageNomineeRelations;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
| Nominee relations are a list the committee keeps (spec 2026-10-07 §5.1): the app and portal read it
| from the API, so adding a relation needs no release.
*/

it('ships the common relations in order', function (): void {
    expect(NomineeRelation::query()->orderBy('sort')->pluck('key')->all())
        ->toBe(['father', 'mother', 'spouse', 'son', 'daughter', 'brother', 'sister', 'other']);
});

it('adds and renames a relation', function (): void {
    $secretary = userWithRole(Role::Secretary);

    $relation = app(SaveNomineeRelation::class)($secretary, null, NomineeRelationData::fromForm([
        'key' => 'grandson', 'label_bn' => 'নাতি', 'label_en' => 'Grandson', 'sort' => 90, 'active' => true,
    ]));

    app(SaveNomineeRelation::class)($secretary, $relation, NomineeRelationData::fromForm([
        'key' => 'grandson', 'label_bn' => 'নাতি', 'label_en' => 'Grandchild', 'sort' => 90, 'active' => true,
    ]));

    expect($relation->fresh()?->label_en)->toBe('Grandchild');
});

it('validates relations', function (array $input, string $key): void {
    expect(memberRuleKey(fn () => app(SaveNomineeRelation::class)(userWithRole(Role::Secretary), null, NomineeRelationData::fromForm($input))))->toBe($key);
})->with([
    'bad key' => [['key' => 'Grand Son', 'label_bn' => 'নাতি', 'label_en' => 'Grandson'], 'members.errors.relation_key_format'],
    'taken key' => [['key' => 'father', 'label_bn' => 'পিতা', 'label_en' => 'Father'], 'members.errors.relation_key_taken'],
    'no label' => [['key' => 'uncle', 'label_bn' => '', 'label_en' => 'Uncle'], 'members.errors.relation_labels_required'],
]);

it('keeps the key once the relation exists', function (): void {
    $relation = NomineeRelation::query()->where('key', 'father')->firstOrFail();

    expect(memberRuleKey(fn () => app(SaveNomineeRelation::class)(userWithRole(Role::Secretary), $relation, NomineeRelationData::fromForm([
        'key' => 'dad', 'label_bn' => 'পিতা', 'label_en' => 'Father',
    ]))))->toBe('members.errors.relation_key_locked');
});

it('lets only staff who edit members change the list', function (): void {
    app(SaveNomineeRelation::class)(userWithRole(Role::Cashier), null, NomineeRelationData::fromForm([
        'key' => 'uncle', 'label_bn' => 'চাচা', 'label_en' => 'Uncle',
    ]));
})->throws(AuthorizationException::class);

it('never deletes a relation', function (): void {
    expect(fn () => NomineeRelation::query()->firstOrFail()->delete())->toThrow(ImmutableRecord::class);
});

it('serves active relations in the request language', function (): void {
    NomineeRelation::query()->where('key', 'other')->firstOrFail()->forceFill(['active' => false])->save();

    $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/config/nominee-relations')
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.key', 'father')
        ->assertJsonPath('data.0.label', 'Father');

    $this->withHeader('Accept-Language', 'bn')->getJson('/api/v1/config/nominee-relations')->assertJsonPath('data.0.label', 'পিতা');
});

it('manages relations from the settings screen', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ManageNomineeRelations::class)
        ->callAction(TestAction::make('create'), ['key' => 'uncle', 'label_bn' => 'চাচা', 'label_en' => 'Uncle', 'sort' => 100, 'active' => true])
        ->assertHasNoFormErrors();

    expect(NomineeRelation::query()->where('key', 'uncle')->exists())->toBeTrue();
});
