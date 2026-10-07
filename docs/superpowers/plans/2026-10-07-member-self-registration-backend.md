# Member Self-Registration (Backend + Web Portal) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The office invites a member with only a mobile + password. The member fills in their own registration on the app or the web portal. It passes a configurable approval chain (default Secretary → President), and final approval creates the real member through the existing `CreateMember`.

**Architecture:** A new `member_applications` aggregate (`app/Domain/Members/Registration`) holds the draft, nominees, chain snapshot and an insert-only decision log. `members` gets no new status, so nothing financial exists before final approval. Sign-in is extended: an open application signs in with an `applicant` token ability that only opens `/api/v1/registration*` and the portal's two registration pages.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5.9, Livewire 4, Sanctum, spatie/permission, spatie/activitylog, Pest 4, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-10-07-member-self-registration-design.md` (open items §12 accepted with the proposed defaults). The Flutter app is covered by a separate plan: `docs/superpowers/plans/2026-10-07-member-self-registration-mobile.md`.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`; Domain classes are `final`; DTOs are `final readonly class`.
- No business logic in Filament/Livewire/controllers: gather input → call one Action → show the result. Filament code never calls `->save(`, `->update(`, `->delete(`, `::create(`, `DB::` (arch test bans it under `app/Filament`).
- Every write Action: `DB::transaction(fn ..., attempts: 3)`, `lockForUpdate()` on the rows it mutates, activity log, `DomainRuleViolation::because('<file>.<key>')` with the key present in **both** `lang/en` and `lang/bn`.
- Enums are backed and implement `HasLabel`, `HasColor`, `HasIcon`.
- Policies on every model; financial/registration records: `delete`/`forceDelete` return false.
- Every user-facing string goes through `__()` and exists in `lang/bn` and `lang/en`.
- Filament write actions use `ConfirmsWithTier` (T1/T2/T3) and `->action()`; every new action has an icon, tooltip and color and is classified in `tests/Feature/ActionInventoryTest.php`.
- Time: `CarbonImmutable`, business dates in `Asia/Dhaka` (`YearMonth::TIMEZONE`).
- Mobile numbers stored as `01XXXXXXXXX` (normalise with `App\Support\Contact\MobileNumber::normalize`); NID 10/13/17 digits (Bangla digits converted with `App\Support\Bangla\BanglaNumber::toAscii`).
- Nominee shares are basis points (`App\Support\Money\Bps`, 100% = 10000).
- After every task: `vendor/bin/pint --dirty --format agent`, the task's tests green. Before finishing: `composer test`, `composer analyse`, `composer format:check`.

## Review Focus

1. **An applicant opens an old app build**, whose dashboard calls member endpoints with an applicant token → expect a 403 with "update the app", never a 401 that makes the app refresh and sign out in a loop. Test in Task 9.
2. **The office uses the old "Create member" form with a mobile that has an open invitation** → expect a refusal ("this mobile has an open registration"), not a second identity for the same phone. Test in Task 4.
3. **The member double-taps Submit, or retries after a network drop, with the same idempotency key** → expect exactly one submission (`submission_no` = 1) and one approver notification. Test in Task 6.
4. **The President gives final approval for a month with no approved rate plan** → expect the existing "no rate plan" message, the application still `submitted`, and no member, due or share lot. Test in Task 7.
5. **A rejected applicant's app uses its refresh token** → expect a 401 and all their tokens gone. Test in Task 9.

## File Structure

**New, domain** (`app/Domain/Members/`):
- `Models/NomineeRelation.php`, `Data/NomineeRelationData.php`, `Actions/SaveNomineeRelation.php`, `Services/NomineeRules.php`
- `Registration/Enums/MemberApplicationStatus.php`, `RegistrationDecisionType.php`, `RegistrationNextAction.php`, `TimelineState.php`
- `Registration/Models/MemberApplication.php`, `MemberApplicationNominee.php`, `MemberApplicationDecision.php`
- `Registration/Data/RegistrationDraft.php`, `TimelineStep.php`
- `Registration/Actions/InviteMember.php`, `SaveRegistrationDraft.php`, `SubmitRegistration.php`, `DecideRegistration.php`, `UpdateRegistrationApprovalChain.php`
- `Registration/Services/RegistrationTimeline.php`, `ApproverNotifier.php`
- `Portal/AccountType.php` (enum), `Portal/AccountTypes.php`

**New, HTTP:** `app/Http/Controllers/Api/Member/RegistrationController.php`, `Concerns/ResolvesApplication.php`, `app/Http/Requests/Api/SaveRegistrationRequest.php`, `RegistrationPhotoRequest.php`, `SubmitRegistrationRequest.php`, `app/Http/Resources/Api/RegistrationResource.php`, `app/Http/Middleware/EnsureApplicantAccess.php`, `RefuseApplicantTokens.php`, `RedirectApplicantsToRegistration.php`

**New, Filament:** `app/Filament/Clusters/Settings/Resources/NomineeRelations/*`, `app/Filament/Clusters/Settings/Pages/RegistrationApprovalsPage.php` (+ blade), `app/Filament/Resources/MemberApplications/*`, `app/Filament/Member/Pages/Registration.php`, `RegistrationStatus.php`, `app/Filament/Member/Concerns/ScopedToApplicant.php`, blades under `resources/views/filament/...`

**New, policies:** `app/Policies/NomineeRelationPolicy.php`, `MemberApplicationPolicy.php`

**New migrations:** `2026_10_07_000001_create_nominee_relations_table.php`, `2026_10_07_000002_create_member_applications_tables.php`

**New lang files:** `lang/{en,bn}/registration.php`

**Modified:** `MemberRules`, `NomineeData`, `NomineeWriter`, `Nominee`, `MemberForm`, `MemberInfolist`, `MemberPresenter`, `CreateMember`, `PortalAccounts`, `MemberCredentials`, `MemberTokens`, `AuthController`, `ConfigController`, `ProfileController`, `routes/api.php`, `bootstrap/app.php`, `ApiExceptionRenderer`, `User`, `SomitiProfile`, `SmsTemplateKey`, `SmsTemplateSeeder`, `ListMembers`, `ScopedToMember`, `MemberPanelProvider`, `LocalDemoSeeder`, `tests/Pest.php`, `lang/{en,bn}/{members,api,sms,somiti}.php`, the tests named in Task 2.

---

### Task 1: Nominee relations list (table, admin, API)

**Files:**
- Create: `database/migrations/2026_10_07_000001_create_nominee_relations_table.php`
- Create: `app/Domain/Members/Models/NomineeRelation.php`, `app/Domain/Members/Data/NomineeRelationData.php`, `app/Domain/Members/Actions/SaveNomineeRelation.php`, `app/Policies/NomineeRelationPolicy.php`
- Create: `app/Filament/Clusters/Settings/Resources/NomineeRelations/NomineeRelationResource.php`, `.../Pages/ManageNomineeRelations.php`
- Modify: `app/Http/Controllers/Api/Member/ConfigController.php`, `routes/api.php`, `lang/{en,bn}/members.php`, `tests/Feature/ActionInventoryTest.php` (only if the run reports a new unclassified name)
- Test: `tests/Feature/Members/NomineeRelationTest.php`

**Interfaces:**
- Produces: `NomineeRelation` (`id`, `key`, `label_bn`, `label_en`, `sort`, `active`, `label(?string $locale = null): string`, `static options(): array<int,string>`, `static isActive(?int $id): bool`); `SaveNomineeRelation::__invoke(User $actor, ?NomineeRelation $relation, NomineeRelationData $data): NomineeRelation`; `GET /api/v1/config/nominee-relations`; the seeded keys `father, mother, spouse, son, daughter, brother, sister, other`.

- [ ] **Step 0: Share the rule-key helper.** `memberRuleKey()` lives in `tests/Feature/Members/MemberTest.php` (lines 30-39), so other files can't use it when run alone. Move it unchanged into `tests/Pest.php` (with `use App\Domain\Shared\Exceptions\DomainRuleViolation;` there) and delete it from `MemberTest.php`:

```php
/**
 * The translation key of the DomainRuleViolation the callback throws, or null when it passes.
 */
function memberRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}
```

- [ ] **Step 1: Write the failing test** — `tests/Feature/Members/NomineeRelationTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Actions\SaveNomineeRelation;
use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
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

it('lets only staff who edit members change the list', function (): void {
    app(SaveNomineeRelation::class)(userWithRole(Role::Cashier), null, NomineeRelationData::fromForm([
        'key' => 'uncle', 'label_bn' => 'চাচা', 'label_en' => 'Uncle',
    ]));
})->throws(AuthorizationException::class);

it('never deletes a relation', function (): void {
    expect(fn () => NomineeRelation::query()->firstOrFail()->delete())->toThrow(\App\Domain\Shared\Exceptions\ImmutableRecord::class);
});

it('serves active relations in the request language', function (): void {
    NomineeRelation::query()->where('key', 'other')->firstOrFail()->forceFill(['active' => false])->save();

    $this->getJson('/api/v1/config/nominee-relations', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.key', 'father')
        ->assertJsonPath('data.0.label', 'Father');

    $this->getJson('/api/v1/config/nominee-relations')->assertJsonPath('data.0.label', 'পিতা');
});

it('manages relations from the settings screen', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ManageNomineeRelations::class)
        ->callAction(TestAction::make('create'), ['key' => 'uncle', 'label_bn' => 'চাচা', 'label_en' => 'Uncle', 'sort' => 100, 'active' => true])
        ->assertHasNoFormErrors();

    expect(NomineeRelation::query()->where('key', 'uncle')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Members/NomineeRelationTest.php`
Expected: FAIL — `Class "App\Domain\Members\Models\NomineeRelation" not found`.

- [ ] **Step 3: Migration** — `database/migrations/2026_10_07_000001_create_nominee_relations_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nominee relations as a list the committee keeps (member self-registration spec §5.1-5.2).
     * Old nominees keep their free-text relation; new and edited ones point at the list.
     */
    public function up(): void
    {
        Schema::create('nominee_relations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('label_bn', 50);
            $table->string('label_en', 50);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE nominee_relations ADD CONSTRAINT nominee_relations_key_format CHECK (key ~ '^[a-z_]{2,30}$')");

        $now = now();

        foreach ([
            ['father', 'পিতা', 'Father'], ['mother', 'মাতা', 'Mother'], ['spouse', 'স্বামী/স্ত্রী', 'Spouse'],
            ['son', 'পুত্র', 'Son'], ['daughter', 'কন্যা', 'Daughter'], ['brother', 'ভাই', 'Brother'],
            ['sister', 'বোন', 'Sister'], ['other', 'অন্যান্য', 'Other'],
        ] as $index => [$key, $bn, $en]) {
            DB::table('nominee_relations')->insert([
                'key' => $key, 'label_bn' => $bn, 'label_en' => $en, 'sort' => ($index + 1) * 10,
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('nominees', function (Blueprint $table): void {
            $table->foreignId('relation_id')->nullable()->after('relation')->constrained('nominee_relations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nominees', fn (Blueprint $table) => $table->dropConstrainedForeignId('relation_id'));
        Schema::dropIfExists('nominee_relations');
    }
};
```

- [ ] **Step 4: Model, DTO, policy, Action**

`app/Domain/Members/Models/NomineeRelation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Policies\NomineeRelationPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A relation a nominee can have to the member (father, spouse, …). Never deleted — nominees point
 * at it; switch it off instead.
 *
 * @property int $id
 * @property string $key
 * @property string $label_bn
 * @property string $label_en
 * @property int $sort
 * @property bool $active
 */
#[UsePolicy(NomineeRelationPolicy::class)]
final class NomineeRelation extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::deleting(fn (self $relation) => throw ImmutableRecord::for(self::class, $relation->getKey()));
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'bn' ? $this->label_bn : $this->label_en;
    }

    /**
     * Active relations for a select, id => label in the current language.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return self::query()->where('active', true)->orderBy('sort')->orderBy('id')->get()
            ->mapWithKeys(fn (self $relation): array => [$relation->id => $relation->label()])
            ->all();
    }

    public static function isActive(?int $id): bool
    {
        return $id !== null && self::query()->whereKey($id)->where('active', true)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort' => 'integer', 'active' => 'boolean'];
    }
}
```

`app/Domain/Members/Data/NomineeRelationData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Data;

final readonly class NomineeRelationData
{
    public function __construct(
        public string $key,
        public string $labelBn,
        public string $labelEn,
        public int $sort = 0,
        public bool $active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data  key, label_bn, label_en, sort, active
     */
    public static function fromForm(array $data): self
    {
        return new self(
            key: trim((string) ($data['key'] ?? '')),
            labelBn: trim((string) ($data['label_bn'] ?? '')),
            labelEn: trim((string) ($data['label_en'] ?? '')),
            sort: (int) ($data['sort'] ?? 0),
            active: (bool) ($data['active'] ?? true),
        );
    }
}
```

`app/Policies/NomineeRelationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Models\NomineeRelation;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

final class NomineeRelationPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Members->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::MembersUpdate);
    }

    public function update(User $user, NomineeRelation $relation): bool
    {
        return $user->may(Permission::MembersUpdate);
    }

    public function delete(User $user, NomineeRelation $relation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, NomineeRelation $relation): bool
    {
        return false;
    }
}
```

`app/Domain/Members/Actions/SaveNomineeRelation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Adds a nominee relation or changes one (labels, order, on/off).
 */
final class SaveNomineeRelation
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, ?NomineeRelation $relation, NomineeRelationData $data): NomineeRelation
    {
        Gate::forUser($actor)->authorize($relation === null ? 'create' : 'update', $relation ?? NomineeRelation::class);

        if (preg_match('/^[a-z_]{2,30}$/', $data->key) !== 1) {
            throw DomainRuleViolation::because('members.errors.relation_key_format');
        }

        if ($data->labelBn === '' || $data->labelEn === '') {
            throw DomainRuleViolation::because('members.errors.relation_labels_required');
        }

        return $this->causer->withCauser($actor, fn (): NomineeRelation => DB::transaction(function () use ($relation, $data): NomineeRelation {
            $taken = NomineeRelation::query()->where('key', $data->key)
                ->when($relation !== null, fn ($query) => $query->whereKeyNot($relation?->getKey()))
                ->exists();

            if ($taken) {
                throw DomainRuleViolation::because('members.errors.relation_key_taken', ['key' => $data->key]);
            }

            $locked = $relation === null ? new NomineeRelation : NomineeRelation::query()->whereKey($relation->getKey())->lockForUpdate()->firstOrFail();

            $locked->fill([
                'key' => $data->key,
                'label_bn' => $data->labelBn,
                'label_en' => $data->labelEn,
                'sort' => $data->sort,
                'active' => $data->active,
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
```

- [ ] **Step 5: Filament resource** (Settings cluster, modal create/edit through the Action)

`app/Filament/Clusters/Settings/Resources/NomineeRelations/NomineeRelationResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\NomineeRelations;

use App\Domain\Members\Actions\SaveNomineeRelation;
use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Filament\Clusters\Settings\Resources\NomineeRelations\Pages\ManageNomineeRelations;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class NomineeRelationResource extends Resource
{
    protected static ?string $model = NomineeRelation::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModelLabel(): string
    {
        return __('members.relation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('members.relation.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('key')->label(__('members.relation.key'))->helperText(__('members.relation.key_help'))->required()->regex('/^[a-z_]{2,30}$/')->maxLength(30),
            TextInput::make('sort')->label(__('members.relation.sort'))->integer()->default(0),
            TextInput::make('label_bn')->label(__('members.relation.label_bn'))->required()->maxLength(50),
            TextInput::make('label_en')->label(__('members.relation.label_en'))->required()->maxLength(50),
            Toggle::make('active')->label(__('members.relation.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('label_bn')->label(__('members.relation.label_bn')),
                TextColumn::make('label_en')->label(__('members.relation.label_en')),
                TextColumn::make('key')->label(__('members.relation.key'))->color('gray'),
                IconColumn::make('active')->label(__('members.relation.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('warning')
                    ->tooltip(__('members.relation.edit'))
                    ->using(fn (NomineeRelation $record, array $data): NomineeRelation => DomainActionRunner::run(
                        fn (User $actor): NomineeRelation => app(SaveNomineeRelation::class)($actor, $record, NomineeRelationData::fromForm($data)),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNomineeRelations::route('/')];
    }
}
```

`app/Filament/Clusters/Settings/Resources/NomineeRelations/Pages/ManageNomineeRelations.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\NomineeRelations\Pages;

use App\Domain\Members\Actions\SaveNomineeRelation;
use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Filament\Clusters\Settings\Resources\NomineeRelations\NomineeRelationResource;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageNomineeRelations extends ManageRecords
{
    protected static string $resource = NomineeRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('members.relation.add'))
                ->tooltip(__('members.relation.add'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->using(fn (array $data): NomineeRelation => DomainActionRunner::run(
                    fn (User $actor): NomineeRelation => app(SaveNomineeRelation::class)($actor, null, NomineeRelationData::fromForm($data)),
                )),
        ];
    }
}
```

- [ ] **Step 6: API endpoint**

In `ConfigController` add (and `use App\Domain\Members\Models\NomineeRelation;`):

```php
    /**
     * The relation list for the nominee dropdown, in the request language (no sign-in needed).
     */
    public function nomineeRelations(): JsonResponse
    {
        return ApiResponse::ok(NomineeRelation::query()->where('active', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (NomineeRelation $relation): array => ['id' => $relation->id, 'key' => $relation->key, 'label' => $relation->label()])
            ->values()->all());
    }
```

In `routes/api.php`, after the `config/logo` route:

```php
    Route::get('config/nominee-relations', [ConfigController::class, 'nomineeRelations']);
```

- [ ] **Step 7: Translations** — in `lang/en/members.php` add a top-level `'relation'` group and these `'errors'` keys; mirror in `lang/bn/members.php`.

```php
    // lang/en/members.php
    'relation' => [
        'singular' => 'Nominee relation',
        'plural' => 'Nominee relations',
        'key' => 'Key',
        'key_help' => 'Lower-case English letters and _ only, e.g. grandson. Never changes once used.',
        'label_bn' => 'Name (Bangla)',
        'label_en' => 'Name (English)',
        'sort' => 'Order',
        'active' => 'In use',
        'add' => 'Add relation',
        'edit' => 'Edit relation',
    ],
    // inside 'errors' => [...]
        'relation_key_format' => 'The key may only have lower-case English letters and _ (2–30).',
        'relation_key_taken' => 'The key :key is already used.',
        'relation_labels_required' => 'Both the Bangla and English names are required.',
```

```php
    // lang/bn/members.php
    'relation' => [
        'singular' => 'নমিনির সম্পর্ক',
        'plural' => 'নমিনির সম্পর্কসমূহ',
        'key' => 'কী',
        'key_help' => 'শুধু ছোট হাতের ইংরেজি অক্ষর ও _, যেমন grandson। একবার ব্যবহারের পর বদলায় না।',
        'label_bn' => 'নাম (বাংলা)',
        'label_en' => 'নাম (ইংরেজি)',
        'sort' => 'ক্রম',
        'active' => 'চালু',
        'add' => 'সম্পর্ক যোগ করুন',
        'edit' => 'সম্পর্ক সম্পাদনা',
    ],
    // inside 'errors' => [...]
        'relation_key_format' => 'কী-তে শুধু ছোট হাতের ইংরেজি অক্ষর ও _ থাকতে পারে (২–৩০টি)।',
        'relation_key_taken' => ':key কী আগেই ব্যবহৃত হয়েছে।',
        'relation_labels_required' => 'বাংলা ও ইংরেজি দুই নামই লাগবে।',
```

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact tests/Feature/Members/NomineeRelationTest.php tests/Feature/ActionInventoryTest.php tests/Feature/Support`
Expected: PASS. If `ActionInventoryTest` reports `ManageNomineeRelations › create` or `edit` unclassified, they are already covered by the generic `'create' => 'T2'` (header create navigates → null) and `'edit' => null` entries; any other report means a missing icon/tooltip/color on the action above.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_07_000001_create_nominee_relations_table.php app/Domain/Members app/Policies/NomineeRelationPolicy.php app/Filament/Clusters/Settings/Resources/NomineeRelations app/Http/Controllers/Api/Member/ConfigController.php routes/api.php lang tests/Feature/Members/NomineeRelationTest.php
git commit -m "Nominee relations: managed list, admin screen and API"
```

---

### Task 2: One nominee rule set (≥ 1 nominee, NID required, relation from the list)

**Files:**
- Create: `app/Domain/Members/Services/NomineeRules.php`
- Modify: `app/Domain/Members/Data/NomineeData.php`, `app/Domain/Members/Services/MemberRules.php`, `app/Domain/Members/Services/NomineeWriter.php`, `app/Domain/Members/Models/Nominee.php`, `app/Filament/Resources/Members/Schemas/MemberForm.php`, `app/Filament/Resources/Members/Schemas/MemberInfolist.php`, `app/Filament/Resources/Members/Support/MemberPresenter.php`, `app/Http/Controllers/Api/Member/ProfileController.php`, `database/seeders/LocalDemoSeeder.php`, `tests/Pest.php`, `lang/{en,bn}/members.php`
- Modify tests: `tests/Feature/Members/MemberTest.php`, `tests/Feature/Members/MemberResourceTest.php`, `tests/Feature/Api/ApiDashboardProfileTest.php`, `tests/Feature/Exits/MemberExitTest.php`

**Interfaces:**
- Consumes: `NomineeRelation::isActive()`, `NomineeRelation::options()` (Task 1).
- Produces: `NomineeData` gains `public ?int $relationId = null` (last constructor argument) and reads `relation_id` and `nid` in `fromForm`; `NomineeRules::assertValid(list<NomineeData> $nominees): void`; `Nominee::relationLabel(): string`; Pest helpers `relationId(string $key): int` and `nominee(array $overrides = []): array`; `memberData()` now includes one valid nominee by default.

- [ ] **Step 1: Pest helpers first** (other tests depend on them). In `tests/Pest.php` add `use App\Domain\Members\Models\NomineeRelation;` and:

```php
/**
 * Id of a seeded nominee relation (father, mother, spouse, son, daughter, brother, sister, other).
 */
function relationId(string $key): int
{
    return (int) NomineeRelation::query()->where('key', $key)->value('id');
}

/**
 * A valid nominee form row; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nominee(array $overrides = []): array
{
    return ['name' => 'Karima', 'relation_id' => relationId('spouse'), 'nid' => '1234567890', 'share_percent' => '100', ...$overrides];
}
```

and change `memberData()` so the defaults include a nominee:

```php
    return MemberData::fromForm([
        'name_bn' => 'রহিম উদ্দিন',
        'name_en' => 'Rahim Uddin',
        'mobile' => sprintf('0171%07d', $counter),
        'joined_on' => '2026-07-01',
        'nominees' => [nominee()],
        ...$overrides,
    ]);
```

- [ ] **Step 2: Write the failing tests** — replace the dataset of `it('validates member details', …)` in `tests/Feature/Members/MemberTest.php` (closures resolve after the database is ready):

```php
})->with([
    'bad mobile' => [['mobile' => '01212345678'], 'members.errors.mobile_format'],
    'taken mobile' => [['mobile' => '01799-999999'], 'members.errors.mobile_taken'],
    'bad nid' => [['nid' => '12345'], 'members.errors.nid_format'],
    'taken nid' => [['nid' => '1111111111'], 'members.errors.nid_taken'],
    'missing english name' => [['name_en' => ' '], 'members.errors.names_required'],
    'no nominee' => [['nominees' => []], 'members.errors.nominee_required'],
    'nominees under 100%' => [fn () => ['nominees' => [nominee(['share_percent' => '60'])]], 'members.errors.nominee_total'],
    'nominee without relation' => [fn () => ['nominees' => [nominee(['relation_id' => null])]], 'members.errors.nominee_relation'],
    'nominee with a switched-off relation' => [function () {
        \App\Domain\Members\Models\NomineeRelation::query()->where('key', 'other')->update(['active' => false]);

        return ['nominees' => [nominee(['relation_id' => relationId('other')])]];
    }, 'members.errors.nominee_relation'],
    'nominee without NID' => [fn () => ['nominees' => [nominee(['nid' => ''])]], 'members.errors.nominee_nid_required'],
    'nominee NID with 11 digits' => [fn () => ['nominees' => [nominee(['nid' => '12345678901'])]], 'members.errors.nominee_nid_format'],
    'nominee mobile with 12 digits' => [fn () => ['nominees' => [nominee(['mobile' => '019877765644'])]], 'members.errors.nominee_mobile_format'],
]);
```

In the same file replace the nominee rows of the onboarding test:

```php
        'nominees' => [
            nominee(['name' => 'Karim', 'relation_id' => relationId('son'), 'share_percent' => '60']),
            nominee(['name' => 'Salma', 'relation_id' => relationId('spouse'), 'nid' => '১২৩৪৫৬৭৮৯০১২৩', 'share_percent' => '40']),
        ],
```

and add after `->and($member->nominees()->pluck('share_bps')->all())->toBe([6000, 4000])`:

```php
        ->and($member->nominees()->pluck('relation_id')->all())->toBe([relationId('son'), relationId('spouse')])
        ->and($member->nominees()->pluck('relation')->all())->toBe(['Son', 'Spouse'])
        ->and($member->nominees()->pluck('nid')->all())->toBe(['1234567890', '1234567890123'])
```

In the "updates details and replaces nominees" test use `nominee(['name' => 'Karim', 'relation_id' => relationId('son')])` and `nominee(['name' => 'Salma'])`.

In `tests/Feature/Members/MemberResourceTest.php`:
- "adds a member…": nominees become `['name' => 'Karim', 'relation_id' => relationId('son'), 'nid' => '1234567890', 'share_percent' => '50']` and `['name' => 'Salma', 'relation_id' => relationId('spouse'), 'nid' => '1234567891', 'share_percent' => '50']`.
- "rejects an invalid mobile" and "reports a missing rate plan": add `'nominees' => [nominee()]` to `fillForm` (with `$undo = Repeater::fake();` … `$undo();` around them, as in the create test).
- "edits a member and their nominees": `onboard(1, '2026-07', ['nominees' => [nominee(['name' => 'Karim', 'relation_id' => relationId('son')])]])`, the `fillForm` nominee row is `['name' => 'Karim', 'relation_id' => relationId('son'), 'nid' => '1234567890', 'share_percent' => '100']`, and add `->assertSchemaStateSet(['nominees.0.relation_id' => relationId('son'), 'nominees.0.nid' => '1234567890'])`.
- Add a test:

```php
it('asks for at least one nominee with an NID', function (): void {
    $undo = Repeater::fake();

    Livewire::test(CreateMember::class)
        ->fillForm(['name_bn' => 'ক', 'name_en' => 'K', 'mobile' => '01812345678', 'shares' => 1, 'effective_from' => '2026-10', 'nominees' => []])
        ->mountAction(memberFormAction('create'))
        ->assertHasFormErrors(['nominees']);

    $undo();
});
```

In `tests/Feature/Api/ApiDashboardProfileTest.php` line 30 use `'nominees' => [nominee(['name' => 'Karima'])]` and change the relation assertion to `->assertJsonPath('data.nominees.0.relation', 'স্বামী/স্ত্রী')`.

In `tests/Feature/Exits/MemberExitTest.php` (deceased split) use `nominee(['name' => 'Ayesha', 'share_percent' => '66.67'])` and `nominee(['name' => 'Rafi', 'relation_id' => relationId('son'), 'share_percent' => '33.33'])`.

- [ ] **Step 3: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/Members/MemberTest.php`
Expected: FAIL — `no nominee`, `nominee without relation`, … report the old keys or none.

- [ ] **Step 4: Implement**

`app/Domain/Members/Data/NomineeData.php` — add the field and read it:

```php
final readonly class NomineeData
{
    public function __construct(
        public string $name,
        public string $relation,
        public Bps $share,
        public ?string $mobile = null,
        public ?string $nid = null,
        public ?int $relationId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  form row: name, relation_id (or legacy relation), share_percent, mobile, nid
     */
    public static function fromForm(array $data): self
    {
        $mobile = $data['mobile'] ?? null;
        $nid = $data['nid'] ?? null;
        $share = $data['share_percent'] ?? '';
        $relationId = $data['relation_id'] ?? null;

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            relation: trim((string) ($data['relation'] ?? '')),
            share: $share instanceof Bps ? $share : Bps::ofPercent((string) $share),
            mobile: is_string($mobile) && trim($mobile) !== '' ? (MobileNumber::normalize($mobile) ?? trim($mobile)) : null,
            nid: is_string($nid) && trim($nid) !== '' ? BanglaNumber::toAscii(preg_replace('/\s+/', '', $nid) ?? $nid) : null,
            relationId: is_numeric($relationId) ? (int) $relationId : null,
        );
    }
}
```
(add `use App\Support\Bangla\BanglaNumber;`)

`app/Domain/Members/Services/NomineeRules.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Bps;

/**
 * Nominees, for the office form and for self-registration alike: at least one; each with a name,
 * a relation from the list, an NID and a share; optional mobile valid; shares add up to 100%.
 */
final class NomineeRules
{
    /**
     * @param  list<NomineeData>  $nominees
     */
    public function assertValid(array $nominees): void
    {
        if ($nominees === []) {
            throw DomainRuleViolation::because('members.errors.nominee_required');
        }

        $total = 0;

        foreach ($nominees as $nominee) {
            if ($nominee->name === '' || $nominee->share->isZero()) {
                throw DomainRuleViolation::because('members.errors.nominee_incomplete');
            }

            if (! NomineeRelation::isActive($nominee->relationId)) {
                throw DomainRuleViolation::because('members.errors.nominee_relation', ['name' => $nominee->name]);
            }

            if ($nominee->nid === null) {
                throw DomainRuleViolation::because('members.errors.nominee_nid_required', ['name' => $nominee->name]);
            }

            if (preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nominee->nid) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_nid_format', ['name' => $nominee->name]);
            }

            if ($nominee->mobile !== null && preg_match('/^01[3-9]\d{8}$/', $nominee->mobile) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_mobile_format', ['name' => $nominee->name]);
            }

            $total += $nominee->share->value;
        }

        if ($total !== 10_000) {
            throw DomainRuleViolation::because('members.errors.nominee_total', ['total' => Bps::of($total)->format(app()->getLocale())]);
        }
    }
}
```

`MemberRules`: add a constructor `public function __construct(private readonly NomineeRules $nominees) {}`, replace `$this->assertNominees($data->nominees);` with `$this->nominees->assertValid($data->nominees);`, delete the private `assertNominees()` method and the now-unused `NomineeData`/`Bps` imports.

`NomineeWriter::replace` — store the relation id and its English name:

```php
        foreach ($nominees as $index => $nominee) {
            $relation = $nominee->relationId === null ? null : NomineeRelation::query()->find($nominee->relationId);

            Nominee::query()->create([
                'member_id' => $member->id,
                'name' => $nominee->name,
                'relation' => $relation === null ? $nominee->relation : $relation->label_en,
                'relation_id' => $relation?->id,
                'mobile' => $nominee->mobile,
                'nid' => $nominee->nid,
                'share_bps' => $nominee->share->value,
                'sort' => $index,
            ]);
        }
```

`Nominee` model — add `@property int|null $relation_id`, `@property-read NomineeRelation|null $nomineeRelation`, and:

```php
    /**
     * @return BelongsTo<NomineeRelation, $this>
     */
    public function nomineeRelation(): BelongsTo
    {
        return $this->belongsTo(NomineeRelation::class, 'relation_id');
    }

    /**
     * The relation in the current language; older nominees show the text typed in at the time.
     */
    public function relationLabel(): string
    {
        return $this->nomineeRelation?->label() ?? $this->relation;
    }
```
and `'relation_id' => 'integer'` in `casts()`.

`MemberForm` — the repeater becomes (keep the existing `share_percent` field and the `nominee_total` entry unchanged):

```php
                        Repeater::make('nominees')
                            ->hiddenLabel()
                            ->addActionLabel(__('members.nominee.add'))
                            ->defaultItems(1)
                            ->minItems(1)
                            ->required()
                            ->columns(6)
                            ->schema([
                                TextInput::make('name')->label(__('members.nominee.name'))->required()->columnSpan(2),
                                Select::make('relation_id')
                                    ->label(__('members.nominee.relation'))
                                    ->options(fn (): array => NomineeRelation::options())
                                    ->required()
                                    ->native(false),
                                TextInput::make('nid')
                                    ->label(__('members.nominee.nid'))
                                    ->required()
                                    ->regex('/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u'),
                                // existing 'mobile' TextInput unchanged
                                // existing 'share_percent' TextInput unchanged
                            ]),
```
(add `use App\Domain\Members\Models\NomineeRelation;` and `use Filament\Forms\Components\Select;`). In `fillFrom()` the nominee row becomes:

```php
            'nominees' => $member->nominees->map(fn (Nominee $nominee): array => [
                'name' => $nominee->name,
                'relation_id' => $nominee->relation_id,
                'nid' => $nominee->nid,
                'mobile' => $nominee->mobile,
                'share_percent' => Bps::of($nominee->share_bps)->toPercentString(),
            ])->values()->all(),
```
An older member without `relation_id`/`nid` then opens with those fields empty, and the form asks for them on save (spec §9 "Member form").

`MemberInfolist` — the nominee entry shows the label and NID:

```php
                            ->columns(5)
                            ->schema([
                                TextEntry::make('name')->hiddenLabel()->weight('bold'),
                                TextEntry::make('relation')->hiddenLabel()->state(fn (Nominee $record): string => $record->relationLabel()),
                                TextEntry::make('nid')->hiddenLabel()->placeholder('—'),
                                // mobile and share_bps entries unchanged
```

`MemberPresenter` (line ~78): pass `$nominee->relationLabel()` instead of `$nominee->relation`, and add `relationId: $nominee->relation_id` as the last argument.

`ProfileController::show`: `'relation' => $nominee->relationLabel(),` (eager-load with `->load('nominees.nomineeRelation')`).

`LocalDemoSeeder::join` — map the demo text to keys and give every nominee an NID; members without a nominee get their first-listed relative:

```php
            'nominees' => array_map(fn (array $nominee): array => [
                'name' => $nominee[0],
                'relation_id' => NomineeRelation::query()->where('key', match ($nominee[1]) {
                    'Wife', 'Husband' => 'spouse',
                    default => strtolower($nominee[1]),
                })->value('id'),
                'nid' => '19900000000'.substr($mobile, -2),
                'share_percent' => $nominee[2],
            ], $nominees),
```
and change Jamal Hossain's row from `[]` to `[['রোকেয়া হোসেন', 'Wife', '100']]`.

Translations — `lang/en/members.php`:

```php
    // 'nominee' group: add
        'nid' => 'NID',
    // 'errors' group: replace nominee_incomplete and add the rest
        'nominee_incomplete' => 'Every nominee needs a name and a share.',
        'nominee_required' => 'Add at least one nominee.',
        'nominee_relation' => 'Choose a relation from the list for nominee :name.',
        'nominee_nid_required' => 'Nominee :name needs an NID.',
        'nominee_nid_format' => 'Nominee :name\'s NID must have 10, 13 or 17 digits.',
```

`lang/bn/members.php`:

```php
        'nid' => 'জাতীয় পরিচয়পত্র নং',
        'nominee_incomplete' => 'প্রত্যেক নমিনির নাম ও অংশ লাগবে।',
        'nominee_required' => 'অন্তত একজন নমিনি যোগ করুন।',
        'nominee_relation' => 'নমিনি :name-এর সম্পর্ক তালিকা থেকে বেছে নিন।',
        'nominee_nid_required' => 'নমিনি :name-এর জাতীয় পরিচয়পত্র নং লাগবে।',
        'nominee_nid_format' => 'নমিনি :name-এর জাতীয় পরিচয়পত্র নং ১০, ১৩ বা ১৭ অঙ্কের হতে হবে।',
```

- [ ] **Step 5: Run the affected tests**

Run: `php artisan test --compact tests/Feature/Members tests/Feature/Api tests/Feature/Exits tests/Feature/Portal tests/Invariants`
Expected: PASS. Any other failure is a test that builds nominees by hand — give it `nominee()` rows.

- [ ] **Step 6: Run the whole suite once** (the nominee default touches every `onboard()`)

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app database/seeders lang tests
git commit -m "Nominees: at least one, NID required, relation from the list"
```

---

### Task 3: Registration schema, enums and models

**Files:**
- Create: `database/migrations/2026_10_07_000002_create_member_applications_tables.php`
- Create: `app/Domain/Members/Registration/Enums/{MemberApplicationStatus,RegistrationDecisionType,RegistrationNextAction,TimelineState}.php`
- Create: `app/Domain/Members/Registration/Models/{MemberApplication,MemberApplicationNominee,MemberApplicationDecision}.php`
- Create: `app/Policies/MemberApplicationPolicy.php`, `lang/en/registration.php`, `lang/bn/registration.php`
- Modify: `app/Domain/Settings/Models/SomitiProfile.php`, `app/Domain/Notifications/Enums/SmsTemplateKey.php`, `database/seeders/SmsTemplateSeeder.php`, `lang/{en,bn}/sms.php`
- Test: `tests/Feature/Registration/RegistrationSchemaTest.php`

**Interfaces:**
- Produces:
  - `MemberApplicationStatus` cases `Invited='invited'`, `Submitted='submitted'`, `Returned='returned'`, `Approved='approved'`, `Rejected='rejected'`; `isOpen(): bool`, `isEditable(): bool`, `static openValues(): list<string>`.
  - `RegistrationDecisionType` `Approve='approve'`, `Return='return'`, `Reject='reject'`.
  - `RegistrationNextAction` `Complete='complete'`, `Resubmit='resubmit'`, `Wait='wait'`, `None='none'`.
  - `TimelineState` `Done='done'`, `Pending='pending'`, `Waiting='waiting'`, `Returned='returned'`, `Rejected='rejected'`.
  - `MemberApplication` properties as the migration; relations `user()`, `nominees()`, `decisions()`, `member()`, `invitedBy()`; methods `chain(): list<Role>`, `currentRole(): ?Role`, `isLastStep(): bool`, `currentDecisions(): Collection<int, MemberApplicationDecision>`, `hasDecidedThisSubmission(User $user): bool`, `nextAction(): RegistrationNextAction`, `toMemberData(CarbonImmutable $joinedOn): MemberData`, `static openForMobile(string $mobile): ?self`.
  - `SomitiProfile::DEFAULT_REGISTRATION_CHAIN = ['secretary', 'president']`, `registrationApprovalChain(): list<Role>`.
  - `SmsTemplateKey::RegistrationReturned`, `RegistrationRejected`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Registration/RegistrationSchemaTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationDecision;
use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function rawApplication(string $mobile = '01811111111', string $status = 'invited'): MemberApplication
{
    $user = User::factory()->create();

    return MemberApplication::query()->create([
        'user_id' => $user->id, 'mobile' => $mobile, 'status' => $status, 'invited_by' => $user->id,
    ]);
}

it('allows one open registration per mobile, any number of closed ones', function (): void {
    rawApplication('01811111111', 'rejected');
    rawApplication('01811111111', 'invited');

    expect(fn () => rawApplication('01811111111', 'submitted'))->toThrow(QueryException::class);
});

it('checks status and mobile in the database', function (): void {
    expect(fn () => rawApplication('01811111111', 'pending'))->toThrow(QueryException::class)
        ->and(fn () => rawApplication('0181111111', 'invited'))->toThrow(QueryException::class);
});

it('never changes or deletes a decision', function (): void {
    $application = rawApplication();
    $decision = MemberApplicationDecision::query()->create([
        'application_id' => $application->id, 'submission_no' => 1, 'step' => 0, 'role' => Role::Secretary,
        'user_id' => $application->user_id, 'decision' => 'approve',
    ]);

    expect(fn () => $decision->update(['reason' => 'x']))->toThrow(ImmutableRecord::class)
        ->and(fn () => DB::table('member_application_decisions')->where('id', $decision->id)->delete())->toThrow(QueryException::class);
});

it('needs a reason to return or reject', function (): void {
    $application = rawApplication();

    expect(fn () => MemberApplicationDecision::query()->create([
        'application_id' => $application->id, 'submission_no' => 1, 'step' => 0, 'role' => Role::Secretary,
        'user_id' => $application->user_id, 'decision' => 'return',
    ]))->toThrow(QueryException::class);
});

it('never deletes an application', function (): void {
    expect(fn () => rawApplication()->delete())->toThrow(ImmutableRecord::class);
});

it('defaults the approval chain to secretary then president', function (): void {
    expect(SomitiProfile::current()->registrationApprovalChain())->toBe([Role::Secretary, Role::President]);
});

it('tells the member what to do next', function (): void {
    $application = rawApplication();

    expect($application->nextAction()->value)->toBe('complete');

    $application->status = MemberApplicationStatus::Returned;
    expect($application->nextAction()->value)->toBe('resubmit');

    $application->status = MemberApplicationStatus::Submitted;
    expect($application->nextAction()->value)->toBe('wait');

    $application->status = MemberApplicationStatus::Rejected;
    expect($application->nextAction()->value)->toBe('none');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Registration/RegistrationSchemaTest.php`
Expected: FAIL — class `MemberApplication` not found.

- [ ] **Step 3: Migration** — `database/migrations/2026_10_07_000002_create_member_applications_tables.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Notifications\Models\SmsTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member self-registration (spec 2026-10-07 §5): the applicant's draft, nominees and the
     * insert-only decision log. No member, due or journal exists until the final approval.
     */
    public function up(): void
    {
        Schema::create('member_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('mobile', 11);
            $table->string('status', 10)->default('invited');
            $table->string('name_bn')->nullable();
            $table->string('name_en')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('nid', 17)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedInteger('requested_shares')->nullable();
            $table->jsonb('approval_chain')->nullable();
            $table->unsignedSmallInteger('current_step')->nullable();
            $table->unsignedInteger('submission_no')->default(0);
            $table->uuid('submit_idempotency_key')->nullable()->unique();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_step']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_applications
                ADD CONSTRAINT member_applications_status_valid CHECK (status IN ('invited', 'submitted', 'returned', 'approved', 'rejected')),
                ADD CONSTRAINT member_applications_mobile_format CHECK (mobile ~ '^01[3-9][0-9]{8}$'),
                ADD CONSTRAINT member_applications_nid_format CHECK (nid IS NULL OR nid ~ '^([0-9]{10}|[0-9]{13}|[0-9]{17})$'),
                ADD CONSTRAINT member_applications_shares_positive CHECK (requested_shares IS NULL OR requested_shares > 0),
                -- Final approval marks the application approved first (so the mobile is free for
                -- CreateMember), then links the member in the same transaction.
                ADD CONSTRAINT member_applications_member_only_when_approved CHECK (member_id IS NULL OR status = 'approved');

            CREATE UNIQUE INDEX member_applications_one_open_per_mobile ON member_applications (mobile)
                WHERE status IN ('invited', 'submitted', 'returned');
            SQL);

        Schema::create('member_application_nominees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('member_applications')->restrictOnDelete();
            $table->string('name');
            $table->foreignId('relation_id')->nullable()->constrained('nominee_relations')->restrictOnDelete();
            $table->string('mobile', 11)->nullable();
            $table->string('nid', 17)->nullable();
            $table->unsignedSmallInteger('share_bps');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE member_application_nominees ADD CONSTRAINT member_application_nominees_share_valid CHECK (share_bps BETWEEN 0 AND 10000)');

        Schema::create('member_application_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('member_applications')->restrictOnDelete();
            $table->unsignedInteger('submission_no');
            $table->unsignedSmallInteger('step');
            $table->string('role', 20);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('decision', 10);
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['application_id', 'submission_no', 'step']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE member_application_decisions
                ADD CONSTRAINT member_application_decisions_valid CHECK (decision IN ('approve', 'return', 'reject')),
                ADD CONSTRAINT member_application_decisions_reason CHECK (decision = 'approve' OR length(trim(coalesce(reason, ''))) >= 5);

            CREATE TRIGGER member_application_decisions_append_only BEFORE UPDATE OR DELETE ON member_application_decisions
                FOR EACH ROW EXECUTE FUNCTION append_only_guard();
            SQL);

        Schema::table('somiti_profiles', function (Blueprint $table): void {
            $table->jsonb('registration_approval_roles')->default(DB::raw("'[\"secretary\",\"president\"]'::jsonb"));
        });

        foreach ([
            'registration_returned' => [
                '{somiti}: আপনার নিবন্ধন সংশোধনের জন্য ফেরত পাঠানো হয়েছে। কারণ: {reason}। অ্যাপ বা {portal_url} থেকে ঠিক করে আবার জমা দিন।',
                '{somiti}: your registration was sent back for correction. Reason: {reason}. Please fix it in the app or at {portal_url} and submit again.',
            ],
            'registration_rejected' => [
                '{somiti}: দুঃখিত, আপনার সদস্য নিবন্ধন গ্রহণ করা হয়নি। কারণ: {reason}। বিস্তারিত জানতে অফিসে যোগাযোগ করুন।',
                '{somiti}: sorry, your membership registration was not accepted. Reason: {reason}. Please contact the office.',
            ],
        ] as $key => [$bn, $en]) {
            SmsTemplate::query()->firstOrCreate(['key' => $key], ['body_bn' => $bn, 'body_en' => $en, 'is_active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('somiti_profiles', fn (Blueprint $table) => $table->dropColumn('registration_approval_roles'));
        Schema::dropIfExists('member_application_decisions');
        Schema::dropIfExists('member_application_nominees');
        Schema::dropIfExists('member_applications');
    }
};
```

Also add the same two rows to the `$defaults` array in `database/seeders/SmsTemplateSeeder.php` (keys `SmsTemplateKey::RegistrationReturned->value` and `SmsTemplateKey::RegistrationRejected->value`), so a fresh install and the migration agree.

- [ ] **Step 4: Enums** (namespace `App\Domain\Members\Registration\Enums`)

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MemberApplicationStatus: string implements HasColor, HasIcon, HasLabel
{
    case Invited = 'invited';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Statuses that hold the mobile number and may still sign in as an applicant.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Invited->value, self::Submitted->value, self::Returned->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    /** The applicant may change the details (before the first submit, or after a return). */
    public function isEditable(): bool
    {
        return $this === self::Invited || $this === self::Returned;
    }

    public function getLabel(): string
    {
        return __('registration.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invited => 'gray',
            self::Submitted => 'info',
            self::Returned => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Invited => Heroicon::OutlinedEnvelope,
            self::Submitted => Heroicon::OutlinedClock,
            self::Returned => Heroicon::OutlinedArrowUturnLeft,
            self::Approved => Heroicon::OutlinedCheckCircle,
            self::Rejected => Heroicon::OutlinedNoSymbol,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RegistrationDecisionType: string implements HasColor, HasIcon, HasLabel
{
    case Approve = 'approve';
    case Return = 'return';
    case Reject = 'reject';

    public function getLabel(): string
    {
        return __('registration.decision.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approve => 'success',
            self::Return => 'warning',
            self::Reject => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Approve => Heroicon::OutlinedCheckCircle,
            self::Return => Heroicon::OutlinedArrowUturnLeft,
            self::Reject => Heroicon::OutlinedNoSymbol,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The one thing the member should do now (spec §4); the apps choose their main button from it.
 */
enum RegistrationNextAction: string implements HasColor, HasIcon, HasLabel
{
    case Complete = 'complete';
    case Resubmit = 'resubmit';
    case Wait = 'wait';
    case None = 'none';

    public function getLabel(): string
    {
        return __('registration.next_action.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Complete, self::Resubmit => 'primary',
            self::Wait, self::None => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Complete => Heroicon::OutlinedPencilSquare,
            self::Resubmit => Heroicon::OutlinedArrowPath,
            self::Wait => Heroicon::OutlinedClock,
            self::None => Heroicon::OutlinedMinusCircle,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TimelineState: string implements HasColor, HasIcon, HasLabel
{
    case Done = 'done';
    case Pending = 'pending';
    case Waiting = 'waiting';
    case Returned = 'returned';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __('registration.timeline_state.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Done => 'success',
            self::Pending => 'warning',
            self::Waiting => 'gray',
            self::Returned => 'warning',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Done => Heroicon::OutlinedCheckCircle,
            self::Pending => Heroicon::OutlinedClock,
            self::Waiting => Heroicon::OutlinedEllipsisHorizontalCircle,
            self::Returned => Heroicon::OutlinedArrowUturnLeft,
            self::Rejected => Heroicon::OutlinedXCircle,
        };
    }
}
```

- [ ] **Step 5: Models** (namespace `App\Domain\Members\Registration\Models`)

`MemberApplication.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Models;

use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationNextAction;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use App\Policies\MemberApplicationPolicy;
use App\Support\Money\Bps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member's own registration: their draft details and nominees, where it is in the approval chain,
 * and — once approved — the member it became. Never deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property string $mobile
 * @property MemberApplicationStatus $status
 * @property string|null $name_bn
 * @property string|null $name_en
 * @property string|null $guardian_name
 * @property string|null $nid
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $email
 * @property string|null $address
 * @property string|null $photo_path
 * @property int|null $requested_shares
 * @property list<string>|null $approval_chain
 * @property int|null $current_step
 * @property int $submission_no
 * @property string|null $submit_idempotency_key
 * @property int $invited_by
 * @property int|null $member_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Collection<int, MemberApplicationNominee> $nominees
 * @property-read Collection<int, MemberApplicationDecision> $decisions
 */
#[UsePolicy(MemberApplicationPolicy::class)]
final class MemberApplication extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::deleting(fn (self $application) => throw ImmutableRecord::for(self::class, $application->getKey()));
    }

    public static function openForMobile(string $mobile): ?self
    {
        return self::query()->where('mobile', $mobile)->whereIn('status', MemberApplicationStatus::openValues())->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return HasMany<MemberApplicationNominee, $this>
     */
    public function nominees(): HasMany
    {
        return $this->hasMany(MemberApplicationNominee::class, 'application_id')->orderBy('sort');
    }

    /**
     * @return HasMany<MemberApplicationDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(MemberApplicationDecision::class, 'application_id')->orderBy('id');
    }

    /**
     * The approval steps of the current submission (snapshot taken at submit).
     *
     * @return list<Role>
     */
    public function chain(): array
    {
        return array_values(array_map(fn (string $role): Role => Role::from($role), $this->approval_chain ?? []));
    }

    public function currentRole(): ?Role
    {
        return $this->status === MemberApplicationStatus::Submitted && $this->current_step !== null
            ? ($this->chain()[$this->current_step] ?? null)
            : null;
    }

    public function isLastStep(): bool
    {
        return $this->current_step !== null && $this->current_step === count($this->chain()) - 1;
    }

    /**
     * @return Collection<int, MemberApplicationDecision>
     */
    public function currentDecisions(): Collection
    {
        return $this->decisions()->where('submission_no', $this->submission_no)->get();
    }

    public function hasDecidedThisSubmission(User $user): bool
    {
        return $this->decisions()->where('submission_no', $this->submission_no)->where('user_id', $user->id)->exists();
    }

    public function nextAction(): RegistrationNextAction
    {
        return match ($this->status) {
            MemberApplicationStatus::Invited => RegistrationNextAction::Complete,
            MemberApplicationStatus::Returned => RegistrationNextAction::Resubmit,
            MemberApplicationStatus::Submitted => RegistrationNextAction::Wait,
            MemberApplicationStatus::Approved, MemberApplicationStatus::Rejected => RegistrationNextAction::None,
        };
    }

    /**
     * The details as the office form would have entered them, for MemberRules and CreateMember.
     */
    public function toMemberData(CarbonImmutable $joinedOn): MemberData
    {
        return new MemberData(
            nameBn: (string) $this->name_bn,
            nameEn: (string) $this->name_en,
            mobile: $this->mobile,
            joinedOn: $joinedOn,
            guardianName: $this->guardian_name,
            nid: $this->nid,
            dateOfBirth: $this->date_of_birth,
            email: $this->email,
            address: $this->address,
            photoPath: $this->photo_path,
            nominees: array_values($this->nominees->map(fn (MemberApplicationNominee $nominee): NomineeData => new NomineeData(
                name: $nominee->name,
                relation: '',
                share: Bps::of($nominee->share_bps),
                mobile: $nominee->mobile,
                nid: $nominee->nid,
                relationId: $nominee->relation_id,
            ))->all()),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('members');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MemberApplicationStatus::class,
            'date_of_birth' => 'immutable_date',
            'approval_chain' => 'array',
            'requested_shares' => 'integer',
            'current_step' => 'integer',
            'submission_no' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
```

`MemberApplicationNominee.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Models;

use App\Domain\Members\Models\NomineeRelation;
use App\Support\Money\Bps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nominee on a registration draft. Replaced as a whole while the applicant may still edit.
 *
 * @property int $id
 * @property int $application_id
 * @property string $name
 * @property int|null $relation_id
 * @property string|null $mobile
 * @property string|null $nid
 * @property int $share_bps
 * @property int $sort
 * @property-read NomineeRelation|null $nomineeRelation
 */
final class MemberApplicationNominee extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<NomineeRelation, $this>
     */
    public function nomineeRelation(): BelongsTo
    {
        return $this->belongsTo(NomineeRelation::class, 'relation_id');
    }

    public function share(): Bps
    {
        return Bps::of($this->share_bps);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['relation_id' => 'integer', 'share_bps' => 'integer', 'sort' => 'integer'];
    }
}
```

`MemberApplicationDecision.php` (same shape as `RatePlanApproval`):

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Models;

use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approver's decision on one step of one submission. Never changed afterwards.
 *
 * @property int $id
 * @property int $application_id
 * @property int $submission_no
 * @property int $step
 * @property Role $role
 * @property int $user_id
 * @property RegistrationDecisionType $decision
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
final class MemberApplicationDecision extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /** @var list<string> */
    protected $with = ['user'];

    protected static function booted(): void
    {
        self::updating(fn (self $decision) => throw ImmutableRecord::for(self::class, $decision->getKey()));
        self::deleting(fn (self $decision) => throw ImmutableRecord::for(self::class, $decision->getKey()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'decision' => RegistrationDecisionType::class,
            'step' => 'integer',
            'submission_no' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
```

- [ ] **Step 6: Policy** — `app/Policies/MemberApplicationPolicy.php`

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

/**
 * Staff see registrations like members; only the role at the current step may decide, and never
 * the applicant's own account. Applicants act through their token, not through this policy.
 */
final class MemberApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Members->allows($user);
    }

    public function view(User $user, MemberApplication $application): bool
    {
        return Area::Members->allows($user);
    }

    /** Invite: create the login for a new member. */
    public function create(User $user): bool
    {
        return $user->may(Permission::MembersCreate);
    }

    public function decide(User $user, MemberApplication $application): bool
    {
        $role = $application->currentRole();

        return $application->status === MemberApplicationStatus::Submitted
            && $role !== null
            && $user->isStaff()
            && $user->hasAnyOf($role)
            && $application->user_id !== $user->id
            && ! $application->hasDecidedThisSubmission($user);
    }

    public function update(User $user, MemberApplication $application): bool
    {
        return false;
    }

    public function delete(User $user, MemberApplication $application): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, MemberApplication $application): bool
    {
        return false;
    }
}
```
(`User::isStaff()` already exists — it is used by `canAccessPanel`.)

- [ ] **Step 7: SomitiProfile, SMS keys, translations**

`SomitiProfile`: add `@property list<string>|null $registration_approval_roles`, and:

```php
    /** Who approves a member's own registration, in order, until the president changes it. */
    public const array DEFAULT_REGISTRATION_CHAIN = ['secretary', 'president'];

    /**
     * @return list<Role>
     */
    public function registrationApprovalChain(): array
    {
        $roles = $this->registration_approval_roles ?? self::DEFAULT_REGISTRATION_CHAIN;

        return array_values(array_map(fn (string $role): Role => Role::from($role), $roles));
    }
```
plus `use App\Enums\Role;`, and extend the existing `casts()` (it has only `registered_on`) with `'registration_approval_roles' => 'array'`.

`SmsTemplateKey`: add cases and placeholders:

```php
    case RegistrationReturned = 'registration_returned';
    case RegistrationRejected = 'registration_rejected';
    // in placeholders():
            self::RegistrationReturned => ['reason', 'somiti', 'portal_url'],
            self::RegistrationRejected => ['reason', 'somiti'],
```
`lang/en/sms.php` → `'template'` group: `'registration_returned' => 'Registration sent back'`, `'registration_rejected' => 'Registration rejected'`; `lang/bn/sms.php`: `'registration_returned' => 'নিবন্ধন ফেরত'`, `'registration_rejected' => 'নিবন্ধন বাতিল'`.

Create `lang/en/registration.php` (later tasks add keys to it; this is the full file used by the whole plan):

```php
<?php

declare(strict_types=1);

return [
    'singular' => 'Registration',
    'plural' => 'Registrations',
    'status' => [
        'invited' => 'Not submitted yet',
        'submitted' => 'Waiting for approval',
        'returned' => 'Sent back for correction',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
    'decision' => [
        'approve' => 'Approved',
        'return' => 'Sent back for correction',
        'reject' => 'Rejected',
    ],
    'next_action' => [
        'complete' => 'Complete your registration',
        'resubmit' => 'Correct and submit again',
        'wait' => 'Waiting for approval',
        'none' => 'Nothing to do',
    ],
    'timeline_state' => [
        'done' => 'Done',
        'pending' => 'Pending',
        'waiting' => 'Waiting',
        'returned' => 'Sent back',
        'rejected' => 'Rejected',
    ],
    'timeline' => [
        'submitted' => 'Information submitted',
        'step' => ':role approval',
        'activation' => 'Membership activated',
    ],
    'headline' => [
        'invited' => 'Your registration is incomplete',
        'waiting' => ':role approval pending',
        'returned' => 'Please correct your registration',
        'rejected' => 'Registration rejected',
        'approved' => 'You are now a member',
    ],
    'message' => [
        'invited' => 'Please complete your member registration to continue.',
        'waiting_first' => 'Your registration was submitted and is waiting for :role approval. You will get an SMS when it changes.',
        'waiting_next' => 'Your registration was approved by the :done and is now waiting for :role approval.',
        'returned' => 'The :role sent your registration back. Please correct it and submit again.',
        'rejected' => 'The :role did not accept your registration. Please contact the office.',
        'approved' => 'Your membership is active. Your member number is :member_no.',
    ],
    'field' => [
        'mobile' => 'Mobile',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'requested_shares' => 'Shares requested',
        'shares' => 'Shares',
        'effective_from' => 'Shares start from (month)',
        'reason' => 'Reason',
        'current_step' => 'Waiting for',
        'submitted_at' => 'Submitted',
        'invited_by' => 'Invited by',
        'photo' => 'Photo',
    ],
    'steps' => [
        'personal' => 'Personal',
        'contact' => 'Contact',
        'nominees' => 'Nominees',
        'shares' => 'Shares',
        'review' => 'Review',
    ],
    'actions' => [
        'invite' => 'Invite member',
        'invite_heading' => 'Invite a new member',
        'invite_description' => 'The member signs in with this mobile and password and fills in their own registration.',
        'approve' => 'Approve',
        'approve_heading' => 'Approve the registration of :name',
        'approve_submit' => 'Approve :mobile',
        'approve_final_description' => 'This is the last approval: the member is created with the shares below and the registration fee is charged.',
        'send_back' => 'Send back for correction',
        'send_back_heading' => 'Send the registration of :name back',
        'send_back_submit' => 'Send back :mobile',
        'reject' => 'Reject permanently',
        'reject_heading' => 'Reject the registration of :name',
        'reject_submit' => 'Reject :mobile',
        'reject_description' => 'The registration closes and this login stops working.',
        'submit' => 'Submit registration',
        'submit_heading' => 'Submit your registration?',
        'submit_description' => 'Check your details. After submitting you cannot change them unless they are sent back to you.',
        'edit' => 'Edit registration',
        'save_chain' => 'Save approval order',
    ],
    'notifications' => [
        'invited' => 'Login created for :mobile. Tell the member the password.',
        'approved_step' => 'Approved. It now waits for :role.',
        'approved_final' => ':name is now member :member_no.',
        'sent_back' => 'Sent back to :name.',
        'rejected' => 'Registration of :name rejected.',
        'waiting_for_you' => 'Registration of :name is waiting for your approval',
        'submitted' => 'Registration submitted.',
        'draft_saved' => 'Saved.',
        'chain_saved' => 'Approval order saved.',
    ],
    'filters' => [
        'waiting_for_me' => 'Waiting for me',
    ],
    'chain' => [
        'title' => 'Registration approvals',
        'subheading' => 'Who approves a member\'s own registration, in this order. Registrations already submitted keep the order they were submitted with.',
        'role' => 'Role',
        'add' => 'Add step',
    ],
    'portal' => [
        'nav' => 'My registration',
        'status_title' => 'Registration status',
        'form_title' => 'Member registration',
        'nominee_total' => 'Total: :total',
    ],
    'errors' => [
        'mobile_invited' => 'Mobile :mobile already has an open registration.',
        'password_short' => 'The password must be at least :min characters.',
        'not_editable' => 'This registration can no longer be changed.',
        'shares_required' => 'Enter how many shares you want (at least 1).',
        'nid_taken' => 'This NID is already used in another registration.',
        'idempotency_conflict' => 'This submission was already used for another registration.',
        'chain_empty' => 'The approval order is empty. Ask the president to set it.',
        'not_pending' => 'This registration is not waiting for approval.',
        'not_your_step' => 'This step is for the :role.',
        'already_decided' => 'You already decided on this registration; another person must approve the next step.',
        'own_registration' => 'You cannot decide on your own registration.',
        'reason_required' => 'Give a reason of at least 5 characters.',
        'effective_from_required' => 'Choose the month the shares start from.',
        'final_needs_create' => 'The last approver must be allowed to add members.',
        'chain_invalid' => 'Choose each role once, from the committee roles.',
        'profile_first' => 'Save the society profile first.',
    ],
];
```

Create `lang/bn/registration.php` with the same keys:

```php
<?php

declare(strict_types=1);

return [
    'singular' => 'নিবন্ধন',
    'plural' => 'নিবন্ধনসমূহ',
    'status' => [
        'invited' => 'এখনও জমা হয়নি',
        'submitted' => 'অনুমোদনের অপেক্ষায়',
        'returned' => 'সংশোধনের জন্য ফেরত',
        'approved' => 'অনুমোদিত',
        'rejected' => 'বাতিল',
    ],
    'decision' => [
        'approve' => 'অনুমোদিত',
        'return' => 'সংশোধনের জন্য ফেরত',
        'reject' => 'বাতিল',
    ],
    'next_action' => [
        'complete' => 'নিবন্ধন সম্পূর্ণ করুন',
        'resubmit' => 'সংশোধন করে আবার জমা দিন',
        'wait' => 'অনুমোদনের অপেক্ষায়',
        'none' => 'কিছু করার নেই',
    ],
    'timeline_state' => [
        'done' => 'সম্পন্ন',
        'pending' => 'অপেক্ষমাণ',
        'waiting' => 'পরবর্তী ধাপ',
        'returned' => 'ফেরত',
        'rejected' => 'বাতিল',
    ],
    'timeline' => [
        'submitted' => 'তথ্য জমা',
        'step' => ':role-এর অনুমোদন',
        'activation' => 'সদস্যপদ চালু',
    ],
    'headline' => [
        'invited' => 'আপনার নিবন্ধন অসম্পূর্ণ',
        'waiting' => ':role-এর অনুমোদনের অপেক্ষায়',
        'returned' => 'আপনার নিবন্ধন সংশোধন করুন',
        'rejected' => 'নিবন্ধন বাতিল হয়েছে',
        'approved' => 'আপনি এখন সদস্য',
    ],
    'message' => [
        'invited' => 'চালিয়ে যেতে আপনার সদস্য নিবন্ধন সম্পূর্ণ করুন।',
        'waiting_first' => 'আপনার নিবন্ধন জমা হয়েছে, এখন :role-এর অনুমোদনের অপেক্ষায়। অবস্থা বদলালে এসএমএস পাবেন।',
        'waiting_next' => ':done আপনার নিবন্ধন অনুমোদন করেছেন, এখন :role-এর অনুমোদনের অপেক্ষায়।',
        'returned' => ':role আপনার নিবন্ধন ফেরত পাঠিয়েছেন। সংশোধন করে আবার জমা দিন।',
        'rejected' => ':role আপনার নিবন্ধন গ্রহণ করেননি। অফিসে যোগাযোগ করুন।',
        'approved' => 'আপনার সদস্যপদ চালু হয়েছে। আপনার সদস্য নং :member_no।',
    ],
    'field' => [
        'mobile' => 'মোবাইল',
        'password' => 'পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড আবার লিখুন',
        'requested_shares' => 'কত শেয়ার চান',
        'shares' => 'শেয়ার',
        'effective_from' => 'শেয়ার শুরুর মাস',
        'reason' => 'কারণ',
        'current_step' => 'যার অপেক্ষায়',
        'submitted_at' => 'জমার সময়',
        'invited_by' => 'আমন্ত্রণকারী',
        'photo' => 'ছবি',
    ],
    'steps' => [
        'personal' => 'ব্যক্তিগত',
        'contact' => 'যোগাযোগ',
        'nominees' => 'নমিনি',
        'shares' => 'শেয়ার',
        'review' => 'যাচাই',
    ],
    'actions' => [
        'invite' => 'সদস্য আমন্ত্রণ',
        'invite_heading' => 'নতুন সদস্য আমন্ত্রণ',
        'invite_description' => 'সদস্য এই মোবাইল ও পাসওয়ার্ড দিয়ে লগইন করে নিজের নিবন্ধন পূরণ করবেন।',
        'approve' => 'অনুমোদন',
        'approve_heading' => ':name-এর নিবন্ধন অনুমোদন',
        'approve_submit' => ':mobile অনুমোদন করুন',
        'approve_final_description' => 'এটি শেষ অনুমোদন: নিচের শেয়ারসহ সদস্য তৈরি হবে এবং ভর্তি ফি ধার্য হবে।',
        'send_back' => 'সংশোধনের জন্য ফেরত',
        'send_back_heading' => ':name-এর নিবন্ধন ফেরত পাঠান',
        'send_back_submit' => ':mobile ফেরত পাঠান',
        'reject' => 'স্থায়ীভাবে বাতিল',
        'reject_heading' => ':name-এর নিবন্ধন বাতিল',
        'reject_submit' => ':mobile বাতিল করুন',
        'reject_description' => 'নিবন্ধনটি বন্ধ হবে এবং এই লগইন আর কাজ করবে না।',
        'submit' => 'নিবন্ধন জমা দিন',
        'submit_heading' => 'নিবন্ধন জমা দেবেন?',
        'submit_description' => 'তথ্য যাচাই করুন। জমার পর ফেরত না আসা পর্যন্ত আর বদলাতে পারবেন না।',
        'edit' => 'নিবন্ধন সম্পাদনা',
        'save_chain' => 'অনুমোদনের ক্রম সংরক্ষণ',
    ],
    'notifications' => [
        'invited' => ':mobile-এর লগইন তৈরি হয়েছে। সদস্যকে পাসওয়ার্ড জানিয়ে দিন।',
        'approved_step' => 'অনুমোদিত। এখন :role-এর অপেক্ষায়।',
        'approved_final' => ':name এখন সদস্য :member_no।',
        'sent_back' => ':name-কে ফেরত পাঠানো হয়েছে।',
        'rejected' => ':name-এর নিবন্ধন বাতিল হয়েছে।',
        'waiting_for_you' => ':name-এর নিবন্ধন আপনার অনুমোদনের অপেক্ষায়',
        'submitted' => 'নিবন্ধন জমা হয়েছে।',
        'draft_saved' => 'সংরক্ষিত।',
        'chain_saved' => 'অনুমোদনের ক্রম সংরক্ষিত।',
    ],
    'filters' => [
        'waiting_for_me' => 'আমার অপেক্ষায়',
    ],
    'chain' => [
        'title' => 'নিবন্ধন অনুমোদন',
        'subheading' => 'সদস্যের নিজের নিবন্ধন কারা, কোন ক্রমে অনুমোদন করবেন। আগে জমা হওয়া নিবন্ধন আগের ক্রমেই চলবে।',
        'role' => 'পদ',
        'add' => 'ধাপ যোগ করুন',
    ],
    'portal' => [
        'nav' => 'আমার নিবন্ধন',
        'status_title' => 'নিবন্ধনের অবস্থা',
        'form_title' => 'সদস্য নিবন্ধন',
        'nominee_total' => 'মোট: :total',
    ],
    'errors' => [
        'mobile_invited' => ':mobile মোবাইলে ইতিমধ্যে একটি চলমান নিবন্ধন আছে।',
        'password_short' => 'পাসওয়ার্ড অন্তত :min অক্ষরের হতে হবে।',
        'not_editable' => 'এই নিবন্ধন আর বদলানো যাবে না।',
        'shares_required' => 'কত শেয়ার চান লিখুন (অন্তত ১টি)।',
        'nid_taken' => 'এই জাতীয় পরিচয়পত্র নং অন্য একটি নিবন্ধনে ব্যবহৃত।',
        'idempotency_conflict' => 'এই জমাটি অন্য একটি নিবন্ধনে ব্যবহৃত হয়েছে।',
        'chain_empty' => 'অনুমোদনের ক্রম খালি। সভাপতিকে ঠিক করতে বলুন।',
        'not_pending' => 'এই নিবন্ধন অনুমোদনের অপেক্ষায় নেই।',
        'not_your_step' => 'এই ধাপটি :role-এর।',
        'already_decided' => 'আপনি এই নিবন্ধনে সিদ্ধান্ত দিয়েছেন; পরের ধাপ অন্য কেউ অনুমোদন করবেন।',
        'own_registration' => 'নিজের নিবন্ধনে সিদ্ধান্ত দেওয়া যায় না।',
        'reason_required' => 'অন্তত ৫ অক্ষরের কারণ লিখুন।',
        'effective_from_required' => 'শেয়ার শুরুর মাস বেছে নিন।',
        'final_needs_create' => 'শেষ অনুমোদনকারীর সদস্য যোগ করার অনুমতি থাকতে হবে।',
        'chain_invalid' => 'কমিটির পদ থেকে প্রতিটি পদ একবার বেছে নিন।',
        'profile_first' => 'আগে সমিতির প্রোফাইল সংরক্ষণ করুন।',
    ],
];
```

- [ ] **Step 8: Run the tests**

Run: `php artisan migrate && php artisan test --compact tests/Feature/Registration/RegistrationSchemaTest.php tests/Feature/Support`
Expected: PASS (including the bn/en key-parity test under `tests/Feature/Support`).

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database app/Domain/Members/Registration app/Policies/MemberApplicationPolicy.php app/Domain/Settings/Models/SomitiProfile.php app/Domain/Notifications/Enums/SmsTemplateKey.php lang tests/Feature/Registration
git commit -m "Registration: applications, decisions, statuses and approval chain setting"
```

---

### Task 4: Invite a member (office creates the login)

**Files:**
- Create: `app/Domain/Members/Registration/Actions/InviteMember.php`
- Modify: `app/Domain/Members/Services/MemberRules.php` (refuse a mobile with an open registration), `tests/Pest.php`
- Test: `tests/Feature/Registration/InviteMemberTest.php`

**Interfaces:**
- Consumes: `MemberApplication`, `MemberApplicationStatus`, `MemberApplicationPolicy::create` (Task 3).
- Produces: `InviteMember::__invoke(User $actor, string $mobile, string $password): MemberApplication`; `MemberRules::assertValid(MemberData $data, ?Member $existing = null, ?int $ignoreApplicationId = null): void`; Pest helper `invite(string $mobile = '01811111111', string $password = 'secret-123'): MemberApplication`.

- [ ] **Step 1: Pest helper** (in `tests/Pest.php`, with `use App\Domain\Members\Registration\Actions\InviteMember;` and `use App\Domain\Members\Registration\Models\MemberApplication;`):

```php
/**
 * The secretary invites a new member (mobile + password); returns the open registration.
 */
function invite(string $mobile = '01811111111', string $password = 'secret-123'): MemberApplication
{
    return app(InviteMember::class)(userWithRole(Role::Secretary), $mobile, $password);
}
```

- [ ] **Step 2: Write the failing test** — `tests/Feature/Registration/InviteMemberTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\InviteMember;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
});

it('creates a member login and an open registration from a mobile and password', function (): void {
    $application = invite('+880 1811-111111', 'secret-123');

    expect($application->status)->toBe(MemberApplicationStatus::Invited)
        ->and($application->mobile)->toBe('01811111111')
        ->and($application->user->hasRole(Role::Member->value))->toBeTrue()
        ->and($application->user->email)->toBeNull()
        ->and(Hash::check('secret-123', $application->user->password))->toBeTrue()
        ->and(\App\Domain\Members\Models\Member::query()->count())->toBe(0);
});

it('refuses a mobile that is a member or already invited, and a short password', function (string $mobile, string $password, string $key): void {
    onboard(1, '2026-07', ['mobile' => '01799999999']);
    invite('01822222222');

    expect(memberRuleKey(fn () => invite($mobile, $password)))->toBe($key);
})->with([
    'bad mobile' => ['12345', 'secret-123', 'members.errors.mobile_format'],
    'member mobile' => ['01799999999', 'secret-123', 'members.errors.mobile_taken'],
    'invited mobile' => ['01822222222', 'secret-123', 'registration.errors.mobile_invited'],
    'short password' => ['01833333333', '123', 'registration.errors.password_short'],
]);

it('lets only staff who add members invite', function (): void {
    app(InviteMember::class)(userWithRole(Role::Cashier), '01811111111', 'secret-123');
})->throws(AuthorizationException::class);

it('stops the office form from creating a member for a mobile with an open registration', function (): void {
    invite('01844444444');

    expect(memberRuleKey(fn () => onboard(1, '2026-07', ['mobile' => '01844444444'])))->toBe('registration.errors.mobile_invited');
});

it('lets the same mobile be invited again after a rejection', function (): void {
    invite('01855555555')->forceFill(['status' => MemberApplicationStatus::Rejected])->save();

    expect(invite('01855555555')->status)->toBe(MemberApplicationStatus::Invited)
        ->and(MemberApplication::query()->where('mobile', '01855555555')->count())->toBe(2);
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Registration/InviteMemberTest.php`
Expected: FAIL — `InviteMember` not found.

- [ ] **Step 4: Implement** — `app/Domain/Members/Registration/Actions/InviteMember.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Step 1 of self-registration (spec §6 R1): the office creates only a login — mobile and password.
 * The member signs in and fills in everything else. No member exists until the final approval.
 */
final class InviteMember
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, string $mobile, string $password): MemberApplication
    {
        Gate::forUser($actor)->authorize('create', MemberApplication::class);

        $normalized = MobileNumber::normalize($mobile);

        if ($normalized === null) {
            throw DomainRuleViolation::because('members.errors.mobile_format');
        }

        if (mb_strlen($password) < SetPortalPassword::MIN_LENGTH) {
            throw DomainRuleViolation::because('registration.errors.password_short', ['min' => SetPortalPassword::MIN_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): MemberApplication => DB::transaction(function () use ($actor, $normalized, $password): MemberApplication {
            // Two clicks on "Invite" for the same number wait for each other instead of racing the unique index.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['invite:'.$normalized]);

            if (Member::withTrashed()->where('mobile', $normalized)->exists()) {
                throw DomainRuleViolation::because('members.errors.mobile_taken', ['mobile' => $normalized]);
            }

            if (MemberApplication::openForMobile($normalized) !== null) {
                throw DomainRuleViolation::because('registration.errors.mobile_invited', ['mobile' => $normalized]);
            }

            $user = User::query()->create([
                'name' => $normalized,
                'email' => null,
                'password' => $password,
                'locale' => 'bn',
            ]);
            $user->assignRole(Role::Member->value);

            $application = MemberApplication::query()->create([
                'user_id' => $user->id,
                'mobile' => $normalized,
                'status' => MemberApplicationStatus::Invited,
                'invited_by' => $actor->id,
            ]);

            activity('members')->performedOn($application)->event('registration_invited')->log('registration invited');

            return $application;
        }, attempts: 3));
    }
}
```
(`User`'s `password` cast is `hashed`, so the plain password is hashed on save. Confirm `SetPortalPassword::MIN_LENGTH` exists — the member actions already use it.)

`MemberRules::assertValid` — add the third parameter and the check, placed right after the `mobile_taken` check:

```php
    public function assertValid(MemberData $data, ?Member $existing = null, ?int $ignoreApplicationId = null): void
    {
        // … existing name / mobile / nid format checks and $others …

        if ((clone $others)->where('mobile', $data->mobile)->exists()) {
            throw DomainRuleViolation::because('members.errors.mobile_taken', ['mobile' => $data->mobile]);
        }

        $openRegistration = MemberApplication::openForMobile($data->mobile);

        if ($existing === null && $openRegistration !== null && $openRegistration->id !== $ignoreApplicationId) {
            throw DomainRuleViolation::because('registration.errors.mobile_invited', ['mobile' => $data->mobile]);
        }

        // … nid_taken check and $this->nominees->assertValid($data->nominees) unchanged …
    }
```
(`use App\Domain\Members\Registration\Models\MemberApplication;`)

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration tests/Feature/Members/MemberTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domain/Members tests
git commit -m "Registration: office invites a member with mobile and password"
```

---

### Task 5: The member saves their draft

**Files:**
- Create: `app/Domain/Members/Registration/Data/RegistrationDraft.php`, `app/Domain/Members/Registration/Actions/SaveRegistrationDraft.php`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Registration/SaveRegistrationDraftTest.php`

**Interfaces:**
- Consumes: `MemberApplication`, `MemberApplicationNominee` (Task 3), `NomineeData::fromForm` (Task 2), `invite()` (Task 4).
- Produces: `RegistrationDraft::FIELDS` (`name_bn, name_en, guardian_name, nid, date_of_birth, email, address, photo_path, requested_shares`); `RegistrationDraft::fromInput(array $input): self` (only keys present are changed; `nominees` present = replace the list); `SaveRegistrationDraft::__invoke(MemberApplication $application, RegistrationDraft $draft): MemberApplication`; Pest helpers `registrationInput(array $overrides = []): array` and `completeRegistration(MemberApplication $application, array $overrides = []): MemberApplication`.

- [ ] **Step 1: Pest helpers** (in `tests/Pest.php`, with the `use` lines for `SaveRegistrationDraft` and `RegistrationDraft`):

```php
/**
 * A complete registration as the member would type it; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationInput(array $overrides = []): array
{
    return [
        'name_bn' => 'করিম মিয়া',
        'name_en' => 'Karim Mia',
        'guardian_name' => 'Abdul Mia',
        'nid' => '9876543210',
        'date_of_birth' => '1990-01-15',
        'address' => 'Mirpur, Dhaka',
        'requested_shares' => 2,
        'nominees' => [nominee()],
        ...$overrides,
    ];
}

/**
 * Saves a complete draft for the applicant.
 *
 * @param  array<string, mixed>  $overrides
 */
function completeRegistration(MemberApplication $application, array $overrides = []): MemberApplication
{
    return app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(registrationInput($overrides)));
}
```

- [ ] **Step 2: Write the failing test** — `tests/Feature/Registration/SaveRegistrationDraftTest.php`

```php
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
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Registration/SaveRegistrationDraftTest.php`
Expected: FAIL — `RegistrationDraft` not found.

- [ ] **Step 4: Implement**

`app/Domain/Members/Registration/Data/RegistrationDraft.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Data;

use App\Domain\Members\Data\NomineeData;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Bangla\BanglaNumber;
use InvalidArgumentException;

/**
 * What the member changed on their registration. Only the fields present are changed; a draft
 * may be incomplete — the full rules run at submit.
 */
final readonly class RegistrationDraft
{
    /** @var list<string> */
    public const array FIELDS = ['name_bn', 'name_en', 'guardian_name', 'nid', 'date_of_birth', 'email', 'address', 'photo_path', 'requested_shares'];

    /**
     * @param  array<string, string|int|null>  $attributes  column => value, only for fields that were sent
     * @param  list<NomineeData>|null  $nominees  null = nominees not sent (keep them)
     */
    public function __construct(public array $attributes, public ?array $nominees = null) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $attributes = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $text = self::text($input[$field]);

            $attributes[$field] = match ($field) {
                'requested_shares' => is_numeric($input[$field]) ? (int) $input[$field] : null,
                'nid' => $text === null ? null : BanglaNumber::toAscii(preg_replace('/\s+/', '', $text) ?? $text),
                default => $text,
            };
        }

        return new self($attributes, is_array($input['nominees'] ?? null) ? self::nominees($input['nominees']) : null);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<NomineeData>
     */
    private static function nominees(array $rows): array
    {
        $nominees = [];

        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }

            $share = $row['share_percent'] ?? '';

            try {
                $nominees[] = NomineeData::fromForm([...$row, 'share_percent' => is_scalar($share) && trim((string) $share) !== '' ? (string) $share : '0']);
            } catch (InvalidArgumentException) {
                throw DomainRuleViolation::because('members.errors.nominee_incomplete');
            }
        }

        return $nominees;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
```

`app/Domain/Members/Registration/Actions/SaveRegistrationDraft.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The member saves (part of) their own registration — before the first submit or after it was
 * sent back. The applicant is always the actor, taken from their sign-in.
 */
final class SaveRegistrationDraft
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(MemberApplication $application, RegistrationDraft $draft): MemberApplication
    {
        $nid = $draft->attributes['nid'] ?? null;

        if (is_string($nid) && preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nid) !== 1) {
            throw DomainRuleViolation::because('members.errors.nid_format');
        }

        $shares = $draft->attributes['requested_shares'] ?? null;

        if (array_key_exists('requested_shares', $draft->attributes) && (! is_int($shares) || $shares < 1)) {
            throw DomainRuleViolation::because('registration.errors.shares_required');
        }

        return $this->causer->withCauser($application->user, fn (): MemberApplication => DB::transaction(function () use ($application, $draft): MemberApplication {
            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw DomainRuleViolation::because('registration.errors.not_editable');
            }

            $oldPhoto = $locked->photo_path;
            $locked->fill($draft->attributes)->save();

            if ($oldPhoto !== null && $oldPhoto !== $locked->photo_path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPhoto));
            }

            if ($draft->nominees !== null) {
                $locked->nominees()->delete();

                foreach ($draft->nominees as $index => $nominee) {
                    $locked->nominees()->create([
                        'name' => $nominee->name,
                        'relation_id' => $nominee->relationId,
                        'mobile' => $nominee->mobile,
                        'nid' => $nominee->nid,
                        'share_bps' => $nominee->share->value,
                        'sort' => $index,
                    ]);
                }
            }

            activity('members')->performedOn($locked)->event('registration_saved')->log('registration saved');

            return $locked->load('nominees');
        }, attempts: 3));
    }
}
```
(Draft nominees carry no financial weight and only live while the applicant may edit, so replacing them is a plain delete + insert, as spec §5.4 says.)

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domain/Members/Registration tests
git commit -m "Registration: the member saves their draft"
```

---

### Task 6: Submit for approval (idempotent) and tell the first approver

**Files:**
- Create: `app/Domain/Members/Registration/Actions/SubmitRegistration.php`, `app/Domain/Members/Registration/Services/ApproverNotifier.php`
- Modify: `app/Http/Api/ApiExceptionRenderer.php` (409 for the idempotency conflict), `tests/Pest.php`
- Test: `tests/Feature/Registration/SubmitRegistrationTest.php`, `tests/Concurrency/RegistrationSubmitTest.php`

**Interfaces:**
- Consumes: `MemberRules::assertValid(…, ignoreApplicationId:)` (Task 4), `SomitiProfile::registrationApprovalChain()` (Task 3), `completeRegistration()` (Task 5).
- Produces: `SubmitRegistration::__invoke(MemberApplication $application, string $idempotencyKey): MemberApplication`; `ApproverNotifier::notifyStep(MemberApplication $application): void`; Pest helper `submittedRegistration(string $mobile = '01811111111'): MemberApplication`.

- [ ] **Step 1: Pest helper**

```php
/**
 * An invited, completed and submitted registration (waiting for the first approver).
 */
function submittedRegistration(string $mobile = '01811111111'): MemberApplication
{
    return app(SubmitRegistration::class)(completeRegistration(invite($mobile)), (string) Str::uuid());
}
```
(`use App\Domain\Members\Registration\Actions\SubmitRegistration;`, `use Illuminate\Support\Str;`)

- [ ] **Step 2: Write the failing test** — `tests/Feature/Registration/SubmitRegistrationTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Enums\Role;
use Illuminate\Support\Str;

it('submits a complete registration to the first step of the chain', function (): void {
    $secretary = userWithRole(Role::Secretary);
    $application = completeRegistration(invite());

    $submitted = app(SubmitRegistration::class)($application, (string) Str::uuid());

    expect($submitted->status)->toBe(MemberApplicationStatus::Submitted)
        ->and($submitted->approval_chain)->toBe(['secretary', 'president'])
        ->and($submitted->current_step)->toBe(0)
        ->and($submitted->submission_no)->toBe(1)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($secretary->notifications()->count())->toBe(1);
});

it('answers a repeated submit with the same key without submitting twice', function (): void {
    userWithRole(Role::Secretary);
    $application = completeRegistration(invite());
    $key = (string) Str::uuid();

    app(SubmitRegistration::class)($application, $key);
    $again = app(SubmitRegistration::class)($application, $key);

    expect($again->submission_no)->toBe(1)
        ->and(\Illuminate\Notifications\DatabaseNotification::query()->count())->toBe(1);
});

it('refuses a key that another registration used', function (): void {
    $key = (string) Str::uuid();
    app(SubmitRegistration::class)(completeRegistration(invite('01811111111')), $key);

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '9876543211']), $key)))
        ->toBe('registration.errors.idempotency_conflict');
});

it('runs the member rules before submitting', function (array $overrides, string $key): void {
    $application = completeRegistration(invite(), $overrides);

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)($application, (string) Str::uuid())))->toBe($key)
        ->and($application->fresh()?->status)->toBe(MemberApplicationStatus::Invited);
})->with([
    'no english name' => [['name_en' => ''], 'members.errors.names_required'],
    'no nominee' => [['nominees' => []], 'members.errors.nominee_required'],
    'nominee without NID' => [fn () => ['nominees' => [nominee(['nid' => ''])]], 'members.errors.nominee_nid_required'],
    'nominees under 100%' => [fn () => ['nominees' => [nominee(['share_percent' => '40'])]], 'members.errors.nominee_total'],
]);

it('needs the requested share count', function (): void {
    $application = completeRegistration(invite());
    $application->forceFill(['requested_shares' => null])->save();

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)($application, (string) Str::uuid())))->toBe('registration.errors.shares_required');
});

it('refuses an NID that a member or another open registration has', function (): void {
    approvedPlan('2026-07', '500');
    onboard(1, '2026-07', ['nid' => '5555555555']);

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)(completeRegistration(invite('01811111111'), ['nid' => '5555555555']), (string) Str::uuid())))
        ->toBe('members.errors.nid_taken');

    submittedRegistration('01822222222'); // default NID 9876543210

    expect(memberRuleKey(fn () => app(SubmitRegistration::class)(completeRegistration(invite('01833333333')), (string) Str::uuid())))
        ->toBe('registration.errors.nid_taken');
});

it('freezes editing once submitted', function (): void {
    $application = submittedRegistration();

    expect(memberRuleKey(fn () => app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['name_bn' => 'x']))))
        ->toBe('registration.errors.not_editable');
});
```

- [ ] **Step 3: Write the concurrency test** — `tests/Concurrency/RegistrationSubmitTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Models\MemberApplication;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    $this->seed([RoleSeeder::class]);
});

afterEach(function (): void {
    DB::reconnect();
    $this->artisan('migrate:fresh');
});

it('submits once when the same submit arrives four times at once', function (): void {
    $applicationId = completeRegistration(invite())->id;
    $key = (string) Str::uuid();

    $results = inParallel(4, function () use ($applicationId, $key): string {
        $application = app(SubmitRegistration::class)(MemberApplication::query()->findOrFail($applicationId), $key);

        return 'submission '.$application->submission_no;
    });

    expect(array_unique($results))->toBe(['submission 1'])
        ->and(MemberApplication::query()->findOrFail($applicationId)->submission_no)->toBe(1);
});
```

- [ ] **Step 4: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Registration/SubmitRegistrationTest.php tests/Concurrency/RegistrationSubmitTest.php`
Expected: FAIL — `SubmitRegistration` not found.

- [ ] **Step 5: Implement**

`app/Domain/Members/Registration/Services/ApproverNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Services;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Tells everyone holding the role at the current step that a registration waits for them
 * (admin panel bell). Sent after commit, so a rolled-back decision notifies nobody.
 */
final class ApproverNotifier
{
    public function notifyStep(MemberApplication $application): void
    {
        $role = $application->currentRole();

        if ($role === null) {
            return;
        }

        $name = $application->name_bn ?? $application->mobile;

        DB::afterCommit(function () use ($role, $name): void {
            $approvers = User::role($role->value)->whereNull('deactivated_at')->get();

            if ($approvers->isEmpty()) {
                return;
            }

            Notification::make()
                ->title(__('registration.notifications.waiting_for_you', ['name' => $name]))
                ->icon(Heroicon::OutlinedUserPlus)
                ->info()
                ->sendToDatabase($approvers);
        });
    }
}
```

`app/Domain/Members/Registration/Actions/SubmitRegistration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Services\ApproverNotifier;
use App\Domain\Members\Services\MemberRules;
use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The member sends their registration for approval (spec §6 R3). The full member rules run now;
 * the approval chain is copied onto the registration so later changes to it do not affect it.
 * The same idempotency key answers with the registration as it is — a retry never submits twice.
 */
final class SubmitRegistration
{
    public function __construct(
        private readonly MemberRules $rules,
        private readonly ApproverNotifier $notifier,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(MemberApplication $application, string $idempotencyKey): MemberApplication
    {
        return $this->causer->withCauser($application->user, fn (): MemberApplication => DB::transaction(function () use ($application, $idempotencyKey): MemberApplication {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['registration-submit:'.$idempotencyKey]);

            if (MemberApplication::query()->where('submit_idempotency_key', $idempotencyKey)->whereKeyNot($application->getKey())->exists()) {
                throw DomainRuleViolation::because('registration.errors.idempotency_conflict');
            }

            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->submit_idempotency_key === $idempotencyKey) {
                return $locked;
            }

            if (! $locked->status->isEditable()) {
                throw DomainRuleViolation::because('registration.errors.not_editable');
            }

            $locked->load('nominees');
            $this->rules->assertValid($locked->toMemberData(CarbonImmutable::now(YearMonth::TIMEZONE)), null, $locked->id);

            if (($locked->requested_shares ?? 0) < 1) {
                throw DomainRuleViolation::because('registration.errors.shares_required');
            }

            $nidInUse = $locked->nid !== null && MemberApplication::query()
                ->whereKeyNot($locked->getKey())
                ->whereIn('status', MemberApplicationStatus::openValues())
                ->where('nid', $locked->nid)
                ->exists();

            if ($nidInUse) {
                throw DomainRuleViolation::because('registration.errors.nid_taken');
            }

            $chain = SomitiProfile::current()->registrationApprovalChain();

            if ($chain === []) {
                throw DomainRuleViolation::because('registration.errors.chain_empty');
            }

            $locked->forceFill([
                'status' => MemberApplicationStatus::Submitted,
                'approval_chain' => array_map(fn (Role $role): string => $role->value, $chain),
                'current_step' => 0,
                'submission_no' => $locked->submission_no + 1,
                'submitted_at' => CarbonImmutable::now(),
                'decided_at' => null,
                'submit_idempotency_key' => $idempotencyKey,
            ])->save();

            activity('members')->performedOn($locked)->event('registration_submitted')->withProperties(['submission_no' => $locked->submission_no])->log('registration submitted');

            $this->notifier->notifyStep($locked);

            return $locked;
        }, attempts: 3));
    }
}
```

`ApiExceptionRenderer`: `private const array CONFLICTS = ['payments.errors.idempotency_conflict', 'registration.errors.idempotency_conflict'];`

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration tests/Concurrency/RegistrationSubmitTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Registration: submit for approval, idempotent, notify the first approver"
```

---

### Task 7: Approve, send back or reject — final approval creates the member

**Files:**
- Create: `app/Domain/Members/Registration/Actions/DecideRegistration.php`
- Modify: `app/Domain/Members/Actions/CreateMember.php`, `app/Domain/Members/Portal/PortalAccounts.php`, `app/Domain/Members/Portal/MemberTokens.php` (add `revokeAccessTokens`), `tests/Pest.php`
- Test: `tests/Feature/Registration/DecideRegistrationTest.php`, `tests/Concurrency/RegistrationApprovalTest.php`

**Interfaces:**
- Consumes: `submittedRegistration()` (Task 6), `ApproverNotifier` (Task 6), `SmsSender`, `SmsTemplateKey::Registration*` (Task 3).
- Produces: `DecideRegistration::__invoke(User $actor, MemberApplication $application, RegistrationDecisionType $decision, ?string $reason = null, ?int $shares = null, ?YearMonth $effectiveFrom = null): MemberApplication`; `CreateMember::__invoke(User $actor, MemberData $data, int $shares, YearMonth $effectiveFrom, ?User $portalUser = null): Member`; `PortalAccounts::forMember(Member $member, ?User $user = null): User`; `MemberTokens::revokeAccessTokens(User $user): void`; Pest helper `approveRegistration(MemberApplication $application, string $from = '2026-07', ?int $shares = null): MemberApplication` (runs every step of the chain with a fresh user per role).

- [ ] **Step 1: Pest helper**

```php
/**
 * Approves every remaining step of the chain, each by a fresh user holding that role.
 */
function approveRegistration(MemberApplication $application, string $from = '2026-07', ?int $shares = null): MemberApplication
{
    while ($application->status === MemberApplicationStatus::Submitted) {
        $role = $application->currentRole() ?? throw new RuntimeException('no current step');
        $application = app(DecideRegistration::class)(
            userWithRole($role),
            $application,
            RegistrationDecisionType::Approve,
            null,
            $shares,
            YearMonth::parse($from),
        );
    }

    return $application;
}
```
(`use` lines for `DecideRegistration`, `RegistrationDecisionType`, `MemberApplicationStatus`.)

- [ ] **Step 2: Write the failing test** — `tests/Feature/Registration/DecideRegistrationTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
});

function decide(Role $role, $application, RegistrationDecisionType $decision, ?string $reason = null, ?int $shares = null, ?string $from = null)
{
    return app(DecideRegistration::class)(userWithRole($role), $application, $decision, $reason, $shares, $from === null ? null : YearMonth::parse($from));
}

it('moves through secretary then president, and only then creates the member', function (): void {
    $application = submittedRegistration();

    $application = decide(Role::Secretary, $application, RegistrationDecisionType::Approve);

    expect($application->status)->toBe(MemberApplicationStatus::Submitted)
        ->and($application->current_step)->toBe(1)
        ->and(Member::query()->count())->toBe(0)
        ->and(Due::query()->count())->toBe(0)
        ->and(ShareLot::query()->count())->toBe(0);

    $application = decide(Role::President, $application, RegistrationDecisionType::Approve, null, 3, '2026-07');
    $member = Member::query()->sole();

    expect($application->status)->toBe(MemberApplicationStatus::Approved)
        ->and($application->member_id)->toBe($member->id)
        ->and($member->status)->toBe(MemberStatus::Active)
        ->and($member->name_en)->toBe('Karim Mia')
        ->and($member->mobile)->toBe('01811111111')
        ->and($member->user_id)->toBe($application->user_id)
        ->and($member->nominees()->sole()->relation_id)->toBe(relationId('spouse'))
        ->and($member->sharesIn(YearMonth::of(2026, 7)))->toBe(3)
        ->and(Due::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and($application->user->fresh()?->name)->toBe('Karim Mia')
        ->and(SmsMessage::query()->where('template_key', 'welcome')->count())->toBe(1);
});

it('uses the requested share count when the approver keeps it', function (): void {
    $member = Member::query()->findOrFail(approveRegistration(submittedRegistration())->member_id);

    expect($member->sharesIn(YearMonth::of(2026, 7)))->toBe(2);
});

it('lets the applicant keep their sign-in after approval: access tokens go, the refresh token stays', function (): void {
    $application = submittedRegistration();
    app(MemberTokens::class)->issue($application->user);

    approveRegistration($application);

    expect($application->user->tokens()->pluck('name')->map(fn (string $name): string => strtok($name, ':'))->all())->toBe(['refresh']);
});

it('only lets the role at the current step decide, one step per person', function (): void {
    $application = submittedRegistration();

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Approve)))->toBe('registration.errors.not_your_step');

    $both = userWithRole(Role::Secretary);
    $both->assignRole(Role::President->value);
    app(DecideRegistration::class)($both, $application, RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => app(DecideRegistration::class)($both, $application->fresh(), RegistrationDecisionType::Approve, null, 2, YearMonth::parse('2026-07'))))
        ->toBe('registration.errors.already_decided');
});

it('needs the start month on the final approval', function (): void {
    $application = decide(Role::Secretary, submittedRegistration(), RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Approve)))->toBe('registration.errors.effective_from_required');
});

it('creates nothing when there is no approved rate plan for the start month', function (): void {
    $application = decide(Role::Secretary, submittedRegistration(), RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Approve, null, 1, '2026-06')))->toBe('members.errors.no_rate_plan');

    $fresh = $application->fresh();

    expect($fresh?->status)->toBe(MemberApplicationStatus::Submitted)
        ->and($fresh?->current_step)->toBe(1)
        ->and($fresh?->decisions()->count())->toBe(1)
        ->and(Member::query()->count())->toBe(0)
        ->and(Due::query()->count())->toBe(0);
});

it('sends a registration back with a reason; the member fixes it and the chain starts again', function (): void {
    $application = decide(Role::Secretary, submittedRegistration(), RegistrationDecisionType::Approve);

    expect(memberRuleKey(fn () => decide(Role::President, $application, RegistrationDecisionType::Return, 'no')))->toBe('registration.errors.reason_required');

    $application = decide(Role::President, $application, RegistrationDecisionType::Return, 'Photo is missing');

    expect($application->status)->toBe(MemberApplicationStatus::Returned)
        ->and(SmsMessage::query()->where('template_key', 'registration_returned')->sole()->body)->toContain('Photo is missing');

    app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(['address' => 'Uttara, Dhaka']));
    $application = app(SubmitRegistration::class)($application, (string) Str::uuid());

    expect($application->submission_no)->toBe(2)
        ->and($application->current_step)->toBe(0)
        ->and($application->decisions()->count())->toBe(2);
});

it('rejects permanently: signs the applicant out and frees the mobile', function (): void {
    $application = submittedRegistration();
    app(MemberTokens::class)->issue($application->user);

    $application = decide(Role::Secretary, $application, RegistrationDecisionType::Reject, 'Not a resident of the area');

    expect($application->status)->toBe(MemberApplicationStatus::Rejected)
        ->and($application->user->tokens()->count())->toBe(0)
        ->and(SmsMessage::query()->where('template_key', 'registration_rejected')->count())->toBe(1)
        ->and(invite('01811111111')->status)->toBe(MemberApplicationStatus::Invited);
});

it('refuses decisions on a registration that is not waiting', function (): void {
    expect(memberRuleKey(fn () => decide(Role::Secretary, completeRegistration(invite()), RegistrationDecisionType::Approve)))->toBe('registration.errors.not_pending');
});
```

- [ ] **Step 3: Concurrency test** — `tests/Concurrency/RegistrationApprovalTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Role;
use App\Models\User;
use App\Support\Time\YearMonth;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    $this->seed([RoleSeeder::class]);
});

afterEach(function (): void {
    DB::reconnect();
    $this->artisan('migrate:fresh');
});

it('creates one member when two presidents approve at the same moment', function (): void {
    approvedPlan('2026-07', '500');
    $application = app(DecideRegistration::class)(userWithRole(Role::Secretary), submittedRegistration(), RegistrationDecisionType::Approve);
    $presidents = [userWithRole(Role::President)->id, userWithRole(Role::President)->id];

    $results = inParallel(2, function (int $index) use ($application, $presidents): string {
        app(DecideRegistration::class)(User::query()->findOrFail($presidents[$index]), MemberApplication::query()->findOrFail($application->id), RegistrationDecisionType::Approve, null, 1, YearMonth::parse('2026-07'));

        return 'approved';
    });

    expect(array_count_values($results)['approved'] ?? 0)->toBe(1)
        ->and(Member::query()->count())->toBe(1);
});
```

- [ ] **Step 4: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/Registration/DecideRegistrationTest.php`
Expected: FAIL — `DecideRegistration` not found.

- [ ] **Step 5: Reuse the applicant's login in CreateMember**

`PortalAccounts::forMember`:

```php
    /**
     * The member's portal login. A member who registered themselves keeps the login they used
     * ($user); otherwise one is created.
     */
    public function forMember(Member $member, ?User $user = null): User
    {
        if ($member->user_id !== null) {
            $existing = User::query()->find($member->user_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => $member->name_en,
                'email' => null,
                'password' => Str::random(48),
                'locale' => 'bn',
            ]);
            $user->assignRole(Role::Member->value);
        } else {
            $user->forceFill(['name' => $member->name_en])->save();
        }

        $member->forceFill(['user_id' => $user->id])->saveQuietly();

        return $user;
    }
```

`CreateMember::__invoke` gains `?User $portalUser = null` as the last parameter and passes it on: `$this->portal->forMember($member, $portalUser);` (the closure's `use` list gets `$portalUser`).

`MemberTokens` — add:

```php
    /**
     * Ends the short-lived access tokens but keeps refresh tokens, so the app's next refresh
     * gets a token for the user's new account type (applicant → member) without signing in.
     */
    public function revokeAccessTokens(User $user): void
    {
        $user->tokens()->where('name', 'like', 'access:%')->delete();
    }
```

- [ ] **Step 6: Implement the decision** — `app/Domain/Members/Registration/Actions/DecideRegistration.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Services\ApproverNotifier;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsSender;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * One approver's decision on the current step (spec §6 R4-R7). Approve moves to the next role;
 * the last approval creates the member with the existing onboarding (member number, shares,
 * registration fee, welcome SMS). Return sends it back for correction; reject closes it for good.
 */
final class DecideRegistration
{
    public const int MIN_REASON = 5;

    public function __construct(
        private readonly CreateMember $createMember,
        private readonly ApproverNotifier $notifier,
        private readonly SmsSender $sms,
        private readonly MemberTokens $tokens,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(
        User $actor,
        MemberApplication $application,
        RegistrationDecisionType $decision,
        ?string $reason = null,
        ?int $shares = null,
        ?YearMonth $effectiveFrom = null,
    ): MemberApplication {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($decision !== RegistrationDecisionType::Approve && mb_strlen((string) $reason) < self::MIN_REASON) {
            throw DomainRuleViolation::because('registration.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): MemberApplication => DB::transaction(function () use ($actor, $application, $decision, $reason, $shares, $effectiveFrom): MemberApplication {
            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $locked);

            if ($locked->status !== MemberApplicationStatus::Submitted) {
                throw DomainRuleViolation::because('registration.errors.not_pending');
            }

            $role = $locked->currentRole();

            if ($role === null || ! $actor->hasAnyOf($role)) {
                throw DomainRuleViolation::because('registration.errors.not_your_step', ['role' => $role?->getLabel() ?? '—']);
            }

            if ($locked->user_id === $actor->id) {
                throw DomainRuleViolation::because('registration.errors.own_registration');
            }

            if ($locked->hasDecidedThisSubmission($actor)) {
                throw DomainRuleViolation::because('registration.errors.already_decided');
            }

            $step = (int) $locked->current_step;

            $locked->decisions()->create([
                'submission_no' => $locked->submission_no,
                'step' => $step,
                'role' => $role,
                'user_id' => $actor->id,
                'decision' => $decision,
                'reason' => $reason,
            ]);

            match (true) {
                $decision === RegistrationDecisionType::Approve && ! $locked->isLastStep() => $this->advance($locked),
                $decision === RegistrationDecisionType::Approve => $this->activate($actor, $locked, $shares, $effectiveFrom),
                $decision === RegistrationDecisionType::Return => $this->sendBack($locked, (string) $reason),
                $decision === RegistrationDecisionType::Reject => $this->reject($locked, (string) $reason),
            };

            activity('members')->performedOn($locked)->event('registration_'.$decision->value)
                ->withProperties(['step' => $step, 'role' => $role->value, 'reason' => $reason])
                ->log('registration '.$decision->value);

            return $locked;
        }, attempts: 3));
    }

    private function advance(MemberApplication $application): void
    {
        $application->forceFill(['current_step' => (int) $application->current_step + 1])->save();
        $this->notifier->notifyStep($application);
    }

    private function activate(User $actor, MemberApplication $application, ?int $shares, ?YearMonth $effectiveFrom): void
    {
        if ($effectiveFrom === null) {
            throw DomainRuleViolation::because('registration.errors.effective_from_required');
        }

        $count = $shares ?? (int) $application->requested_shares;

        if ($count < 1) {
            throw DomainRuleViolation::because('members.errors.shares_positive');
        }

        if (! $actor->may(Permission::MembersCreate)) {
            throw DomainRuleViolation::because('registration.errors.final_needs_create');
        }

        $application->load('nominees');
        $data = $application->toMemberData(CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay());

        // Closed first, so the mobile is no longer "invited" when the member rules check it.
        $application->forceFill(['status' => MemberApplicationStatus::Approved, 'current_step' => null, 'decided_at' => CarbonImmutable::now()])->save();

        $member = ($this->createMember)($actor, $data, $count, $effectiveFrom, $application->user);

        $application->forceFill(['member_id' => $member->id])->save();
        $this->tokens->revokeAccessTokens($application->user);
    }

    private function sendBack(MemberApplication $application, string $reason): void
    {
        $application->forceFill(['status' => MemberApplicationStatus::Returned, 'current_step' => null, 'decided_at' => CarbonImmutable::now()])->save();

        $this->sms->template(SmsTemplateKey::RegistrationReturned, $application->mobile, [
            'reason' => $reason,
            'portal_url' => url('/portal'),
        ], null, sprintf('registration:%d:%d:return', $application->id, $application->submission_no), $application);
    }

    private function reject(MemberApplication $application, string $reason): void
    {
        $application->forceFill(['status' => MemberApplicationStatus::Rejected, 'current_step' => null, 'decided_at' => CarbonImmutable::now()])->save();
        $this->tokens->revokeAll($application->user);

        $this->sms->template(SmsTemplateKey::RegistrationRejected, $application->mobile, [
            'reason' => $reason,
        ], null, sprintf('registration:%d:%d:reject', $application->id, $application->submission_no), $application);
    }
}
```

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration tests/Feature/Members tests/Concurrency/RegistrationApprovalTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Registration: approve, send back or reject; the last approval creates the member"
```

---

### Task 8: The status timeline the member and staff see

**Files:**
- Create: `app/Domain/Members/Registration/Data/TimelineStep.php`, `app/Domain/Members/Registration/Services/RegistrationTimeline.php`, `resources/views/filament/registration/timeline.blade.php`
- Test: `tests/Feature/Registration/RegistrationTimelineTest.php`

**Interfaces:**
- Consumes: everything from Tasks 3-7.
- Produces: `TimelineStep` (`string $key`, `string $label`, `TimelineState $state`, `?CarbonImmutable $actedAt`, `?string $actorName`, `?string $reason`); `RegistrationTimeline::steps(MemberApplication): list<TimelineStep>`, `headline(MemberApplication): string`, `message(MemberApplication): string`, `decision(MemberApplication): ?MemberApplicationDecision`; blade view `filament.registration.timeline` taking `$steps`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Registration/RegistrationTimelineTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Services\RegistrationTimeline;
use App\Enums\Role;

beforeEach(function (): void {
    app()->setLocale('en');
    approvedPlan('2026-07', '500');
});

/**
 * @return list<string>  "key:state"
 */
function shape($application): array
{
    return array_map(fn (TimelineStep $step): string => $step->key.':'.$step->state->value, app(RegistrationTimeline::class)->steps($application));
}

it('shows the configured chain before the first submit', function (): void {
    $application = invite();

    expect(shape($application))->toBe(['submitted:pending', 'step_0:waiting', 'step_1:waiting', 'activation:waiting'])
        ->and(app(RegistrationTimeline::class)->headline($application))->toBe('Your registration is incomplete')
        ->and(app(RegistrationTimeline::class)->steps($application)[1]->label)->toBe('Secretary approval');
});

it('marks done, pending and waiting steps while approvals run', function (): void {
    $application = submittedRegistration();
    expect(shape($application))->toBe(['submitted:done', 'step_0:pending', 'step_1:waiting', 'activation:waiting'])
        ->and(app(RegistrationTimeline::class)->headline($application))->toBe('Secretary approval pending');

    $application = app(DecideRegistration::class)(userWithRole(Role::Secretary), $application, RegistrationDecisionType::Approve);
    expect(shape($application))->toBe(['submitted:done', 'step_0:done', 'step_1:pending', 'activation:waiting'])
        ->and(app(RegistrationTimeline::class)->message($application))->toContain('approved by the Secretary');

    $application = approveRegistration($application);
    expect(shape($application))->toBe(['submitted:done', 'step_0:done', 'step_1:done', 'activation:done']);
});

it('shows who sent it back and why', function (): void {
    $application = app(DecideRegistration::class)(userWithRole(Role::Secretary), submittedRegistration(), RegistrationDecisionType::Return, 'NID photo unclear');

    $steps = app(RegistrationTimeline::class)->steps($application);
    $decision = app(RegistrationTimeline::class)->decision($application);

    expect(shape($application))->toBe(['submitted:done', 'step_0:returned', 'step_1:waiting', 'activation:waiting'])
        ->and($steps[1]->reason)->toBe('NID photo unclear')
        ->and($decision?->reason)->toBe('NID photo unclear')
        ->and(app(RegistrationTimeline::class)->headline($application))->toBe('Please correct your registration');
});
```
(`Role::Secretary->getLabel()` in `en` must read "Secretary" — check `lang/en/roles.php`; if it differs, use its value in the expected strings.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Registration/RegistrationTimelineTest.php`
Expected: FAIL — `TimelineStep` not found.

- [ ] **Step 3: Implement**

`app/Domain/Members/Registration/Data/TimelineStep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Data;

use App\Domain\Members\Registration\Enums\TimelineState;
use Carbon\CarbonImmutable;

/**
 * One line of the registration status the member sees ("✓ Secretary approval").
 */
final readonly class TimelineStep
{
    public function __construct(
        public string $key,
        public string $label,
        public TimelineState $state,
        public ?CarbonImmutable $actedAt = null,
        public ?string $actorName = null,
        public ?string $reason = null,
    ) {}
}
```

`app/Domain/Members/Registration/Services/RegistrationTimeline.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Services;

use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Enums\TimelineState;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationDecision;
use App\Domain\Settings\Models\SomitiProfile;

/**
 * Turns a registration into what a member can read: submitted → each approval → active, with
 * who acted, when and why. Role names come from the chain, so the apps never hard-code them.
 */
final class RegistrationTimeline
{
    /**
     * @return list<TimelineStep>
     */
    public function steps(MemberApplication $application): array
    {
        $invited = $application->status === MemberApplicationStatus::Invited;
        $chain = $invited ? SomitiProfile::current()->registrationApprovalChain() : $application->chain();
        $decisions = $invited ? collect() : $application->currentDecisions()->keyBy('step');

        $steps = [new TimelineStep(
            'submitted',
            __('registration.timeline.submitted'),
            $invited ? TimelineState::Pending : TimelineState::Done,
            $application->submitted_at,
        )];

        foreach ($chain as $index => $role) {
            /** @var MemberApplicationDecision|null $decision */
            $decision = $decisions->get($index);

            $state = match (true) {
                $decision?->decision === RegistrationDecisionType::Approve => TimelineState::Done,
                $decision?->decision === RegistrationDecisionType::Return => TimelineState::Returned,
                $decision?->decision === RegistrationDecisionType::Reject => TimelineState::Rejected,
                $application->status === MemberApplicationStatus::Submitted && $application->current_step === $index => TimelineState::Pending,
                default => TimelineState::Waiting,
            };

            $steps[] = new TimelineStep(
                'step_'.$index,
                __('registration.timeline.step', ['role' => $role->getLabel()]),
                $state,
                $decision?->created_at,
                $decision?->user->name,
                $decision?->reason,
            );
        }

        $approved = $application->status === MemberApplicationStatus::Approved;
        $steps[] = new TimelineStep(
            'activation',
            __('registration.timeline.activation'),
            $approved ? TimelineState::Done : TimelineState::Waiting,
            $approved ? $application->decided_at : null,
        );

        return $steps;
    }

    public function headline(MemberApplication $application): string
    {
        return match ($application->status) {
            MemberApplicationStatus::Invited => __('registration.headline.invited'),
            MemberApplicationStatus::Submitted => __('registration.headline.waiting', ['role' => $application->currentRole()?->getLabel() ?? '']),
            MemberApplicationStatus::Returned => __('registration.headline.returned'),
            MemberApplicationStatus::Rejected => __('registration.headline.rejected'),
            MemberApplicationStatus::Approved => __('registration.headline.approved'),
        };
    }

    public function message(MemberApplication $application): string
    {
        $last = $application->status === MemberApplicationStatus::Invited ? null : $application->currentDecisions()->last();
        $current = $application->currentRole()?->getLabel() ?? '';

        return match ($application->status) {
            MemberApplicationStatus::Invited => __('registration.message.invited'),
            MemberApplicationStatus::Submitted => $last === null
                ? __('registration.message.waiting_first', ['role' => $current])
                : __('registration.message.waiting_next', ['done' => $last->role->getLabel(), 'role' => $current]),
            MemberApplicationStatus::Returned => __('registration.message.returned', ['role' => $last?->role->getLabel() ?? '']),
            MemberApplicationStatus::Rejected => __('registration.message.rejected', ['role' => $last?->role->getLabel() ?? '']),
            MemberApplicationStatus::Approved => __('registration.message.approved', ['member_no' => $application->member()->value('member_no') ?? '']),
        };
    }

    /**
     * The return or rejection the member must read, if that is where the registration stands.
     */
    public function decision(MemberApplication $application): ?MemberApplicationDecision
    {
        if (! in_array($application->status, [MemberApplicationStatus::Returned, MemberApplicationStatus::Rejected], true)) {
            return null;
        }

        return $application->currentDecisions()->last();
    }
}
```

`resources/views/filament/registration/timeline.blade.php` (shared by the admin view entry and the portal status page):

```blade
@php
    use App\Domain\Members\Registration\Enums\TimelineState;
    use App\Filament\Support\Display;

    /** @var list<\App\Domain\Members\Registration\Data\TimelineStep> $steps */
    $steps = isset($getState) ? ($getState() ?? []) : ($steps ?? []);
@endphp

<ol class="space-y-4">
    @foreach ($steps as $step)
        <li class="flex gap-3">
            <x-filament::icon
                :icon="$step->state->getIcon()"
                @class([
                    'h-6 w-6 shrink-0',
                    'text-success-600 dark:text-success-400' => $step->state === TimelineState::Done,
                    'text-warning-600 dark:text-warning-400' => in_array($step->state, [TimelineState::Pending, TimelineState::Returned], true),
                    'text-danger-600 dark:text-danger-400' => $step->state === TimelineState::Rejected,
                    'text-gray-400' => $step->state === TimelineState::Waiting,
                ])
            />
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ $step->label }}</span>
                    <x-filament::badge :color="$step->state->getColor()">{{ $step->state->getLabel() }}</x-filament::badge>
                </div>
                @if ($step->actedAt)
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ Display::dateTime($step->actedAt) }}@if ($step->actorName) · {{ $step->actorName }}@endif
                    </div>
                @endif
                @if ($step->reason)
                    <div class="text-sm">{{ $step->reason }}</div>
                @endif
            </div>
        </li>
    @endforeach
</ol>
```

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domain/Members/Registration resources/views/filament/registration tests
git commit -m "Registration: status timeline with role names from the chain"
```

---

### Task 9: Sign-in for applicants — one login, two token kinds

**Files:**
- Create: `app/Domain/Members/Portal/AccountType.php`, `app/Domain/Members/Portal/AccountTypes.php`, `app/Http/Middleware/EnsureApplicantAccess.php`, `app/Http/Middleware/RefuseApplicantTokens.php`
- Modify: `app/Domain/Members/Portal/MemberCredentials.php`, `app/Domain/Members/Portal/MemberTokens.php`, `app/Http/Controllers/Api/Member/AuthController.php`, `routes/api.php`, `bootstrap/app.php`, `lang/{en,bn}/api.php`
- Test: `tests/Feature/Api/ApiApplicantAuthTest.php`; add one assertion to `tests/Feature/Api/ApiAuthTest.php`

**Interfaces:**
- Consumes: `MemberApplication::openForMobile()`, `approveRegistration()`, `DecideRegistration` (Task 7).
- Produces: `AccountType` enum (`Member='member'`, `Applicant='applicant'`); `AccountTypes::of(User $user): ?AccountType`, `AccountTypes::openApplicationOf(User $user): ?MemberApplication`; login/refresh `data.account_type`; `GET auth/me` applicant shape; middleware aliases `ability`, `applicant`, `refuse-applicant`; request attribute `application` (set by `EnsureApplicantAccess`); translation `api.registration.member_only`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Api/ApiApplicantAuthTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Enums\Role;
use Laravel\Sanctum\PersonalAccessToken;

/*
| A registration's login signs in like a member's but gets an "applicant" token that only opens the
| registration screens (spec §7). After the final approval the next refresh gives a member token.
*/

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->application = invite('01811111111', 'secret-123');
});

function applicantLogin(): array
{
    return test()->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'secret-123'])->assertOk()->json('data');
}

it('signs an invited member in with an applicant token', function (): void {
    $tokens = applicantLogin();

    expect($tokens['account_type'])->toBe('applicant')
        ->and(PersonalAccessToken::findToken($tokens['access_token'])?->abilities)->toBe(['applicant']);

    $this->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'wrong'])->assertStatus(422);
});

it('tells the app where the registration stands', function (): void {
    $this->withToken(applicantLogin()['access_token'])
        ->getJson('/api/v1/auth/me', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.account_type', 'applicant')
        ->assertJsonPath('data.mobile', '01811111111')
        ->assertJsonPath('data.registration.status.value', 'invited')
        ->assertJsonPath('data.registration.next_action', 'complete');
});

it('answers member screens with 403 "update the app" and keeps the applicant signed in', function (): void {
    $tokens = applicantLogin();

    $this->withToken($tokens['access_token'])
        ->getJson('/api/v1/dashboard/summary')
        ->assertStatus(403)
        ->assertJsonPath('message', __('api.registration.member_only'));

    expect(PersonalAccessToken::query()->count())->toBe(2);
});

it('turns into a member token on the next refresh after the final approval', function (): void {
    $tokens = applicantLogin();
    approveRegistration(submittedRegistrationFrom($this->application));
    app('auth')->forgetGuards();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);

    $fresh = $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data');
    app('auth')->forgetGuards();

    expect($fresh['account_type'])->toBe('member');
    $this->withToken($fresh['access_token'])->getJson('/api/v1/dashboard/summary')->assertOk();
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertJsonPath('data.account_type', 'member');
});

it('refuses a rejected applicant everywhere', function (): void {
    $tokens = applicantLogin();
    app(DecideRegistration::class)(userWithRole(Role::Secretary), submittedRegistrationFrom($this->application), RegistrationDecisionType::Reject, 'Not from this area');
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['mobile' => '01811111111', 'password' => 'secret-123'])->assertStatus(422);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});
```

and the Pest helper it uses (in `tests/Pest.php`):

```php
/**
 * Completes and submits an existing invitation.
 */
function submittedRegistrationFrom(MemberApplication $application): MemberApplication
{
    return app(SubmitRegistration::class)(completeRegistration($application), (string) Str::uuid());
}
```

In `tests/Feature/Api/ApiAuthTest.php`, first test, add `->assertJsonPath('data.account_type', 'member')`.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Api/ApiApplicantAuthTest.php`
Expected: FAIL — login answers 422 for the invited mobile.

- [ ] **Step 3: Account types**

`app/Domain/Members/Portal/AccountType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Who is behind a member-side login: a member, or someone still registering.
 */
enum AccountType: string implements HasColor, HasIcon, HasLabel
{
    case Member = 'member';
    case Applicant = 'applicant';

    public function getLabel(): string
    {
        return __('portal.account_type.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Member ? 'success' : 'warning';
    }

    public function getIcon(): Heroicon
    {
        return $this === self::Member ? Heroicon::OutlinedUser : Heroicon::OutlinedUserPlus;
    }
}
```
(add `'account_type' => ['member' => 'Member', 'applicant' => 'Registering']` to `lang/en/portal.php` and `['member' => 'সদস্য', 'applicant' => 'নিবন্ধনাধীন']` to `lang/bn/portal.php`.)

`app/Domain/Members/Portal/AccountTypes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;

/**
 * Member-side logins are either members (not exited) or people with an open registration.
 * Anything else — staff, exited members, rejected registrations — has no member-side access.
 */
final class AccountTypes
{
    public function __construct(private readonly PortalAccounts $portal) {}

    public function of(User $user): ?AccountType
    {
        if ($user->isStaff()) {
            return null;
        }

        if ($this->portal->activeMemberOf($user) !== null) {
            return AccountType::Member;
        }

        return $this->openApplicationOf($user) === null ? null : AccountType::Applicant;
    }

    public function openApplicationOf(User $user): ?MemberApplication
    {
        return MemberApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('status', MemberApplicationStatus::openValues())
            ->first();
    }
}
```

- [ ] **Step 4: Credentials and tokens**

`MemberCredentials::byPassword` — fall back to an open registration when no member has the mobile:

```php
    public function byPassword(string $mobile, string $password): ?User
    {
        $member = $this->member($mobile);

        if ($member !== null) {
            $user = $this->accounts->forMember($member);

            return Hash::check($password, $user->password) ? $user : null;
        }

        $normalized = MobileNumber::normalize($mobile);
        $application = $normalized === null ? null : MemberApplication::openForMobile($normalized);

        return $application !== null && Hash::check($password, $application->user->password) ? $application->user : null;
    }
```
(Sign-in codes stay member-only: `LoginCodes::verify` looks up members. Update the class doc comment: "Members who have not exited, and people with an open registration, can sign in.")

`MemberTokens`:

```php
    public function __construct(private readonly AccountTypes $accounts) {}

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, account_type: string}
     */
    public function issue(User $user, ?string $family = null): array
    {
        $family ??= (string) Str::uuid();
        $now = CarbonImmutable::now();
        $type = $this->accounts->of($user) ?? AccountType::Member;

        $access = $user->createToken('access:'.$family, [$type->value], $now->addMinutes(self::ACCESS_MINUTES));
        $refresh = $user->createToken('refresh:'.$family, ['refresh'], $now->addDays(self::REFRESH_DAYS));

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_MINUTES * 60,
            'account_type' => $type->value,
        ];
    }
```
(`AccountType::Member->value` is `'member'` and `Applicant->value` is `'applicant'`, so the token ability equals the account type.) Update `refresh()`'s `@return` shape the same way, and inside its transaction, right after the `refresh:` prefix/ability/expiry check, add:

```php
            // A rejected registration or an exited member has nothing left to refresh into.
            if ($this->accounts->of($user) === null) {
                $this->revokeAll($user);

                return null;
            }
```

- [ ] **Step 5: Middleware**

`app/Http/Middleware/RefuseApplicantTokens.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member screens answer a registering person's token with 403 and a hint to update the app —
 * not with 401, which would make an old app build refresh and sign out in a loop.
 */
final class RefuseApplicantTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->tokenCan('applicant') && ! $user->tokenCan('member')) {
            return ApiResponse::error(__('api.registration.member_only'), 403);
        }

        return $next($request);
    }
}
```

`app/Http/Middleware/EnsureApplicantAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\AccountTypes;
use App\Http\Api\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the open registration behind an applicant token. Controllers read it from the request,
 * never from input.
 */
final class EnsureApplicantAccess
{
    public function __construct(private readonly AccountTypes $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $application = $user instanceof User ? $this->accounts->openApplicationOf($user) : null;

        if ($application === null) {
            return ApiResponse::error(__('api.errors.forbidden'), 403);
        }

        $request->attributes->set('application', $application);

        return $next($request);
    }
}
```

`bootstrap/app.php` — extend the alias array:

```php
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'member' => EnsureMemberAccess::class,
            'applicant' => EnsureApplicantAccess::class,
            'refuse-applicant' => RefuseApplicantTokens::class,
```
(`use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;` and the two middleware classes.)

- [ ] **Step 6: Routes and `/me`**

`routes/api.php` — replace the authenticated group with:

```php
    Route::middleware(['auth:sanctum', 'ability:member,applicant', 'throttle:member-api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });

    // Registration routes are added in Task 10 inside this group.
    Route::middleware(['auth:sanctum', 'abilities:applicant', 'applicant', 'throttle:member-api'])->group(function (): void {
    });

    Route::middleware(['auth:sanctum', 'refuse-applicant', 'abilities:member', 'member', 'throttle:member-api'])->group(function (): void {
        Route::get('dashboard/summary', [DashboardController::class, 'summary']);
        // … every other existing member route, unchanged …
    });
```

`AuthController::me`:

```php
    public function me(Request $request, AccountTypes $accounts, PortalAccounts $portal): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($accounts->of($user) === AccountType::Applicant) {
            $application = $accounts->openApplicationOf($user);
            abort_if($application === null, 403);

            return ApiResponse::ok([
                'account_type' => AccountType::Applicant->value,
                'mobile' => $application->mobile,
                'registration' => [
                    'status' => ApiValue::enum($application->status),
                    'next_action' => $application->nextAction()->value,
                ],
            ]);
        }

        $member = $portal->activeMemberOf($user);

        if ($member === null) {
            $this->tokens->revokeAll($user);

            return ApiResponse::error(__('api.errors.forbidden'), 403);
        }

        return ApiResponse::ok([
            'account_type' => AccountType::Member->value,
            'member_no' => $member->member_no,
            'name' => self::memberName($member),
            'status' => ApiValue::enum($member->status),
        ]);
    }
```

`lang/en/api.php` add `'registration' => ['member_only' => 'Your registration is not approved yet. Please update the app if you do not see your registration status.']`; `lang/bn/api.php`: `'registration' => ['member_only' => 'আপনার নিবন্ধন এখনও অনুমোদিত হয়নি। নিবন্ধনের অবস্থা না দেখলে অ্যাপটি আপডেট করুন।']`.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Api`
Expected: PASS (`ApiAuthTest`, `ApiIsolationTest` unchanged apart from the new `account_type` assertion).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app bootstrap routes lang tests
git commit -m "Sign-in: applicant tokens for open registrations; refresh switches to member after approval"
```

---

### Task 10: Registration API

**Files:**
- Create: `app/Http/Controllers/Api/Member/RegistrationController.php`, `app/Http/Controllers/Api/Member/Concerns/ResolvesApplication.php`, `app/Http/Requests/Api/SaveRegistrationRequest.php`, `app/Http/Requests/Api/RegistrationPhotoRequest.php`, `app/Http/Requests/Api/SubmitRegistrationRequest.php`, `app/Http/Resources/Api/RegistrationResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ApiRegistrationTest.php`

**Interfaces:**
- Consumes: `SaveRegistrationDraft`, `RegistrationDraft`, `SubmitRegistration`, `RegistrationTimeline`, `TimelineStep` (Tasks 5-8); `applicant` middleware (Task 9).
- Produces: `GET|PUT /api/v1/registration`, `POST /api/v1/registration/photo`, `POST /api/v1/registration/submit`, `GET /api/v1/registration/photo/{application}` (signed, name `api.registration.photo`); `RegistrationResource::make(MemberApplication): array` — the exact shape in spec §8.4, used by the mobile plan.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Api/ApiRegistrationTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Portal\MemberTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    userWithRole(\App\Enums\Role::Secretary);
    $this->application = invite('01811111111');
    $this->token = app(MemberTokens::class)->issue($this->application->user)['access_token'];
    $this->withToken($this->token)->withHeader('Accept-Language', 'en');
});

it('shows an empty registration with the steps ahead', function (): void {
    $this->getJson('/api/v1/registration')
        ->assertOk()
        ->assertJsonPath('data.status.value', 'invited')
        ->assertJsonPath('data.next_action', 'complete')
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.headline', 'Your registration is incomplete')
        ->assertJsonCount(4, 'data.timeline')
        ->assertJsonPath('data.timeline.1.label', 'Secretary approval')
        ->assertJsonPath('data.timeline.1.state', 'waiting')
        ->assertJsonPath('data.data.mobile', '01811111111')
        ->assertJsonPath('data.data.nominees', []);
});

it('saves a draft and returns it', function (): void {
    $this->putJson('/api/v1/registration', ['name_bn' => 'করিম মিয়া', 'requested_shares' => 2, 'nominees' => [nominee()]])
        ->assertOk()
        ->assertJsonPath('data.data.name_bn', 'করিম মিয়া')
        ->assertJsonPath('data.data.requested_shares', 2)
        ->assertJsonPath('data.data.nominees.0.relation', 'Spouse')
        ->assertJsonPath('data.data.nominees.0.share_percent', '100.00');
});

it('names the fields that are wrong', function (): void {
    $this->putJson('/api/v1/registration', ['nid' => '12', 'date_of_birth' => '2999-01-01', 'nominees' => [nominee(['relation_id' => 9999, 'nid' => '1'])]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['nid', 'date_of_birth', 'nominees.0.relation_id', 'nominees.0.nid'], 'errors');
});

it('stores the photo privately and serves it through a signed link', function (): void {
    Storage::fake('local');

    $url = $this->post('/api/v1/registration/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])
        ->assertOk()
        ->json('data.data.photo_url');

    expect($url)->toContain('signature=');
    Storage::disk('local')->assertExists((string) $this->application->fresh()?->photo_path);
    $this->get($url)->assertOk();
});

it('submits once per key and then waits for the secretary', function (): void {
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key])
        ->assertOk()
        ->assertJsonPath('data.status.value', 'submitted')
        ->assertJsonPath('data.next_action', 'wait')
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.headline', 'Secretary approval pending')
        ->assertJsonPath('data.timeline.1.state', 'pending');

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key])->assertOk()->assertJsonPath('data.status.value', 'submitted');
    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid()])->assertStatus(422);
    $this->putJson('/api/v1/registration', ['name_bn' => 'x'])->assertStatus(422)->assertJsonPath('message', __('registration.errors.not_editable', [], 'en'));
});

it('explains why a submit is refused', function (): void {
    $this->putJson('/api/v1/registration', registrationInput(['nominees' => [nominee(['share_percent' => '60'])]]))->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('members.errors.nominee_total', ['total' => '60.00%'], 'en'));
});

it('answers a reused key from another registration with 409', function (): void {
    $key = (string) Str::uuid();
    app(\App\Domain\Members\Registration\Actions\SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), $key);
    $this->putJson('/api/v1/registration', registrationInput())->assertOk();

    $this->postJson('/api/v1/registration/submit', ['idempotency_key' => $key])->assertStatus(409);
});

it('keeps members out of the registration screens', function (): void {
    $member = onboard(1, '2026-07', ['mobile' => '01799999999']);
    app('auth')->forgetGuards();

    $this->withToken(memberToken($member))->getJson('/api/v1/registration')->assertStatus(401);
});
```
(If the `Bps` percent formatter prints `60%` instead of `60.00%` in English, use `__('members.errors.nominee_total', ['total' => \App\Support\Money\Bps::of(6000)->format('en')], 'en')` as the expected message.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Api/ApiRegistrationTest.php`
Expected: FAIL — 404 on `/api/v1/registration`.

- [ ] **Step 3: Requests**

`app/Http/Requests/Api/SaveRegistrationRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Support\Contact\MobileNumber;
use App\Support\Money\Bps;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * One step of the member's own registration form; every field optional (partial saves),
 * each checked for its format. The complete rules run when the member submits.
 */
final class SaveRegistrationRequest extends FormRequest
{
    private const string NID = '/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name_bn' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nid' => ['sometimes', 'nullable', 'string', 'regex:'.self::NID],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'requested_shares' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'nominees' => ['sometimes', 'array', 'max:10'],
            'nominees.*.name' => ['required', 'string', 'max:255'],
            'nominees.*.relation_id' => ['nullable', 'integer', Rule::exists('nominee_relations', 'id')->where('active', true)],
            'nominees.*.nid' => ['nullable', 'string', 'regex:'.self::NID],
            'nominees.*.mobile' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && MobileNumber::normalize($value) === null) {
                    $fail(__('members.errors.mobile_format'));
                }
            }],
            'nominees.*.share_percent' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    Bps::ofPercent((string) $value);
                } catch (InvalidArgumentException) {
                    $fail(__('money.validation.invalid'));
                }
            }],
        ];
    }
}
```

`RegistrationPhotoRequest`: `'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:1024']`. `SubmitRegistrationRequest`: `'idempotency_key' => ['required', 'uuid']`. Both `final`, same shape as `SubmitPaymentRequest`.

- [ ] **Step 4: Resource** — `app/Http/Resources/Api/RegistrationResource.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationNominee;
use App\Domain\Members\Registration\Services\RegistrationTimeline;
use App\Http\Api\ApiValue;
use Illuminate\Support\Facades\URL;

/**
 * The registration as the app shows it (spec §8.4): status, the one next action, the timeline
 * with role names already translated, the decision to read, and the draft itself.
 */
final class RegistrationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(MemberApplication $application): array
    {
        $timeline = app(RegistrationTimeline::class);
        $decision = $timeline->decision($application);
        $application->loadMissing('nominees.nomineeRelation');

        return [
            'status' => ApiValue::enum($application->status),
            'next_action' => $application->nextAction()->value,
            'can_edit' => $application->status->isEditable(),
            'headline' => $timeline->headline($application),
            'message' => $timeline->message($application),
            'timeline' => array_map(fn (TimelineStep $step): array => [
                'key' => $step->key,
                'label' => $step->label,
                'state' => $step->state->value,
                'acted_at' => ApiValue::time($step->actedAt),
                'actor' => $step->actorName,
                'reason' => $step->reason,
            ], $timeline->steps($application)),
            'decision' => $decision === null ? null : [
                'type' => ApiValue::enum($decision->decision),
                'by_role' => $decision->role->getLabel(),
                'at' => ApiValue::time($decision->created_at),
                'reason' => $decision->reason,
            ],
            'data' => [
                'name_bn' => $application->name_bn,
                'name_en' => $application->name_en,
                'guardian_name' => $application->guardian_name,
                'nid' => $application->nid,
                'date_of_birth' => ApiValue::date($application->date_of_birth),
                'mobile' => $application->mobile,
                'email' => $application->email,
                'address' => $application->address,
                'photo_url' => $application->photo_path === null ? null : URL::temporarySignedRoute('api.registration.photo', now()->addHour(), ['application' => $application->id]),
                'requested_shares' => $application->requested_shares,
                'nominees' => $application->nominees->map(fn (MemberApplicationNominee $nominee): array => [
                    'name' => $nominee->name,
                    'relation_id' => $nominee->relation_id,
                    'relation' => $nominee->nomineeRelation?->label(),
                    'mobile' => $nominee->mobile,
                    'nid' => $nominee->nid,
                    'share_percent' => $nominee->share()->toPercentString(),
                ])->values()->all(),
            ],
        ];
    }
}
```

- [ ] **Step 5: Controller** — `Concerns/ResolvesApplication.php` and `RegistrationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member\Concerns;

use App\Domain\Members\Registration\Models\MemberApplication;
use Illuminate\Http\Request;

/**
 * The signed-in person's open registration, as resolved by EnsureApplicantAccess from the token.
 */
trait ResolvesApplication
{
    protected static function application(Request $request): MemberApplication
    {
        $application = $request->attributes->get('application');

        abort_unless($application instanceof MemberApplication, 403);

        return $application;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\Member\Concerns\ResolvesApplication;
use App\Http\Requests\Api\RegistrationPhotoRequest;
use App\Http\Requests\Api\SaveRegistrationRequest;
use App\Http\Requests\Api\SubmitRegistrationRequest;
use App\Http\Resources\Api\RegistrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's own registration in the app (spec §8.4-8.7). The registration always comes from
 * the token, never from input.
 */
final class RegistrationController
{
    use ResolvesApplication;

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(RegistrationResource::make(self::application($request)));
    }

    public function update(SaveRegistrationRequest $request, SaveRegistrationDraft $save): JsonResponse
    {
        $application = $save(self::application($request), RegistrationDraft::fromInput($request->validated()));

        return ApiResponse::ok(RegistrationResource::make($application), __('registration.notifications.draft_saved'));
    }

    public function photo(RegistrationPhotoRequest $request, SaveRegistrationDraft $save): JsonResponse
    {
        $path = (string) $request->file('photo')?->store('member-photos', 'local');

        try {
            $application = $save(self::application($request), RegistrationDraft::fromInput(['photo_path' => $path]));
        } catch (DomainRuleViolation $violation) {
            Storage::disk('local')->delete($path);

            throw $violation;
        }

        return ApiResponse::ok(RegistrationResource::make($application), __('registration.notifications.draft_saved'));
    }

    public function submit(SubmitRegistrationRequest $request, SubmitRegistration $submit): JsonResponse
    {
        $application = $submit(self::application($request), (string) $request->string('idempotency_key'));

        return ApiResponse::ok(RegistrationResource::make($application), __('registration.notifications.submitted'));
    }

    /**
     * Token-free on purpose: the app shows it in an image widget; the signature protects it.
     */
    public function photoFile(MemberApplication $application): Response
    {
        abort_if($application->photo_path === null || ! Storage::disk('local')->exists($application->photo_path), 404);

        return Storage::disk('local')->response($application->photo_path);
    }
}
```

`routes/api.php` — next to the other signed routes:

```php
    Route::get('registration/photo/{application}', [RegistrationController::class, 'photoFile'])
        ->middleware('signed')->whereNumber('application')->name('api.registration.photo');
```
and inside the applicant group from Task 9:

```php
        Route::get('registration', [RegistrationController::class, 'show']);
        Route::put('registration', [RegistrationController::class, 'update']);
        Route::post('registration/photo', [RegistrationController::class, 'photo']);
        Route::post('registration/submit', [RegistrationController::class, 'submit']);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Api`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http routes tests
git commit -m "API: the member's own registration (show, save, photo, submit)"
```

---

### Task 11: Admin — invite, registrations list, approve / send back / reject

**Files:**
- Create: `app/Filament/Resources/MemberApplications/MemberApplicationResource.php`, `Pages/ListMemberApplications.php`, `Pages/ViewMemberApplication.php`, `Schemas/MemberApplicationInfolist.php`, `Tables/MemberApplicationsTable.php`, `Actions/RegistrationActions.php`
- Modify: `app/Filament/Resources/Members/Pages/ListMembers.php`, `app/Domain/Members/Registration/Models/MemberApplication.php` (query scope), `tests/Feature/ActionInventoryTest.php`
- Test: `tests/Feature/Registration/RegistrationAdminTest.php`

**Interfaces:**
- Consumes: `InviteMember`, `DecideRegistration`, `RegistrationTimeline`, the `filament.registration.timeline` view (Tasks 4-8).
- Produces: `MemberApplication::scopeWaitingFor(Builder $query, User $user)`; action names `invite` (T2), `approve`, `sendBack`, `reject` (T3).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Registration/RegistrationAdminTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\Members\Pages\ListMembers;
use Database\Seeders\SmsTemplateSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    approvedPlan('2026-07', '500');
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
});

it('invites a member from the members list', function (): void {
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ListMembers::class)
        ->callAction('invite', ['mobile' => '01811-111111', 'password' => 'secret-123', 'password_confirmation' => 'secret-123'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(MemberApplication::query()->sole()->mobile)->toBe('01811111111');
});

it('lists registrations and filters those waiting for me', function (): void {
    $waiting = submittedRegistration('01811111111');
    $draft = invite('01822222222');
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ListMemberApplications::class)
        ->assertCanSeeTableRecords([$waiting, $draft])
        ->filterTable('waiting_for_me')
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('takes a registration through both approvals after typing the mobile', function (): void {
    $application = submittedRegistration();
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertSee('Secretary approval')
        ->callAction('approve', ['confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text'])
        ->callAction('approve', ['confirm_text' => '01811111111'])
        ->assertHasNoActionErrors();

    expect($application->fresh()?->current_step)->toBe(1);

    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertActionVisible('approve')
        ->callAction('approve', ['shares' => 3, 'effective_from' => '2026-07', 'confirm_text' => '01811111111'])
        ->assertHasNoActionErrors();

    expect($application->fresh()?->status)->toBe(MemberApplicationStatus::Approved)
        ->and(Member::query()->sole()->sharesIn(\App\Support\Time\YearMonth::of(2026, 7)))->toBe(3);
});

it('hides the decision buttons from the wrong role', function (): void {
    $application = submittedRegistration();
    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewMemberApplication::class, ['record' => $application->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('sendBack')
        ->assertActionHidden('reject');
});

it('sends back and rejects with a reason', function (): void {
    $returned = submittedRegistration('01811111111');
    $rejected = app(SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), (string) Str::uuid());
    $this->actingAs(userWithRole(Role::Secretary));

    Livewire::test(ViewMemberApplication::class, ['record' => $returned->getRouteKey()])
        ->callAction('sendBack', ['reason' => 'Photo missing', 'confirm_text' => '01811111111'])
        ->assertHasNoActionErrors();

    Livewire::test(ViewMemberApplication::class, ['record' => $rejected->getRouteKey()])
        ->callAction('reject', ['reason' => 'Lives outside the area', 'confirm_text' => '01822222222'])
        ->assertHasNoActionErrors();

    expect($returned->fresh()?->status)->toBe(MemberApplicationStatus::Returned)
        ->and($rejected->fresh()?->status)->toBe(MemberApplicationStatus::Rejected);
});
```
(Add `use App\Domain\Members\Registration\Actions\SubmitRegistration;` and `use Illuminate\Support\Str;`. A second submitted registration needs its own NID — the default `9876543210` is already held by the first one.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Registration/RegistrationAdminTest.php`
Expected: FAIL — `ListMemberApplications` not found / `invite` action missing.

- [ ] **Step 3: Query scope** — in `MemberApplication`:

```php
    /**
     * Registrations whose current step is for one of the user's roles.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaitingFor(Builder $query, User $user): void
    {
        $roles = $user->getRoleNames()->all();

        $query->where('status', MemberApplicationStatus::Submitted)
            ->whereRaw('(approval_chain ->> current_step) = ANY(?::text[])', ['{'.implode(',', $roles).'}']);
    }
```
(`use Illuminate\Database\Eloquent\Builder;`)

- [ ] **Step 4: Actions** — `app/Filament/Resources/MemberApplications/Actions/RegistrationActions.php`

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications\Actions;

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Actions\InviteMember;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use App\Support\Time\YearMonth;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RegistrationActions
{
    use ConfirmsWithTier;

    /**
     * T2 (the form is the summary): mobile + password only; the member fills in the rest.
     */
    public static function invite(): Action
    {
        return self::tier2InForm(Action::make('invite')
            ->label(__('registration.actions.invite'))
            ->tooltip(__('registration.actions.invite'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', MemberApplication::class))
            ->modalHeading(__('registration.actions.invite_heading'))
            ->modalDescription(__('registration.actions.invite_description'))
            ->schema([
                TextInput::make('mobile')
                    ->label(__('registration.field.mobile'))
                    ->tel()
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (MobileNumber::normalize(is_string($value) ? $value : null) === null) {
                            $fail(__('members.errors.mobile_format'));
                        }
                    }),
                TextInput::make('password')
                    ->label(__('registration.field.password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(SetPortalPassword::MIN_LENGTH)
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label(__('registration.field.password_confirmation'))
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $application = DomainActionRunner::run(fn (User $actor): MemberApplication => app(InviteMember::class)($actor, (string) $data['mobile'], (string) $data['password']));

                Notification::make()
                    ->title(__('registration.notifications.invited', ['mobile' => Display::digits($application->mobile)]))
                    ->success()
                    ->persistent()
                    ->send();
            }));
    }

    public static function approve(): Action
    {
        $final = fn (MemberApplication $record): bool => $record->isLastStep();

        $action = Action::make('approve')
            ->label(__('registration.actions.approve'))
            ->tooltip(__('registration.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('decide')
            ->action(function (MemberApplication $record, array $data): void {
                $from = is_string($data['effective_from'] ?? null) && $data['effective_from'] !== '' ? YearMonth::parse($data['effective_from']) : null;
                $shares = isset($data['shares']) && $data['shares'] !== '' ? (int) $data['shares'] : null;

                $result = DomainActionRunner::run(fn (User $actor): MemberApplication => app(DecideRegistration::class)($actor, $record, RegistrationDecisionType::Approve, null, $shares, $from));

                Notification::make()
                    ->title($result->status === MemberApplicationStatus::Approved
                        ? __('registration.notifications.approved_final', ['name' => self::name($result), 'member_no' => (string) $result->member()->value('member_no')])
                        : __('registration.notifications.approved_step', ['role' => $result->currentRole()?->getLabel() ?? '']))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberApplication $record): string => __('registration.actions.approve_heading', ['name' => self::name($record)]),
            expected: fn (MemberApplication $record): string => $record->mobile,
            submitLabel: fn (MemberApplication $record): string => __('registration.actions.approve_submit', ['mobile' => $record->mobile]),
            description: fn (MemberApplication $record): ?string => $record->isLastStep() ? __('registration.actions.approve_final_description') : null,
            fields: [
                TextInput::make('shares')
                    ->label(__('registration.field.shares'))
                    ->integer()
                    ->minValue(1)
                    ->default(fn (MemberApplication $record): ?int => $record->requested_shares)
                    ->required($final)
                    ->visible($final),
                TextInput::make('effective_from')
                    ->label(__('registration.field.effective_from'))
                    ->type('month')
                    ->regex('/^\d{4}-\d{2}$/')
                    ->default(fn (): string => (string) YearMonth::current())
                    ->required($final)
                    ->visible($final),
            ],
        );
    }

    public static function sendBack(): Action
    {
        $action = Action::make('sendBack')
            ->label(__('registration.actions.send_back'))
            ->tooltip(__('registration.actions.send_back'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize('decide')
            ->action(function (MemberApplication $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): MemberApplication => app(DecideRegistration::class)($actor, $record, RegistrationDecisionType::Return, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('registration.notifications.sent_back', ['name' => self::name($record)]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberApplication $record): string => __('registration.actions.send_back_heading', ['name' => self::name($record)]),
            expected: fn (MemberApplication $record): string => $record->mobile,
            submitLabel: fn (MemberApplication $record): string => __('registration.actions.send_back_submit', ['mobile' => $record->mobile]),
            fields: [Textarea::make('reason')->label(__('registration.field.reason'))->required()->minLength(DecideRegistration::MIN_REASON)->rows(2)],
        );
    }

    public static function reject(): Action
    {
        $action = Action::make('reject')
            ->label(__('registration.actions.reject'))
            ->tooltip(__('registration.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('decide')
            ->action(function (MemberApplication $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): MemberApplication => app(DecideRegistration::class)($actor, $record, RegistrationDecisionType::Reject, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('registration.notifications.rejected', ['name' => self::name($record)]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberApplication $record): string => __('registration.actions.reject_heading', ['name' => self::name($record)]),
            expected: fn (MemberApplication $record): string => $record->mobile,
            submitLabel: fn (MemberApplication $record): string => __('registration.actions.reject_submit', ['mobile' => $record->mobile]),
            description: __('registration.actions.reject_description'),
            fields: [Textarea::make('reason')->label(__('registration.field.reason'))->required()->minLength(DecideRegistration::MIN_REASON)->rows(2)],
        );
    }

    private static function name(MemberApplication $application): string
    {
        return (app()->getLocale() === 'bn' ? $application->name_bn : $application->name_en) ?? $application->mobile;
    }
}
```

`ListMembers::getHeaderActions()` — add `RegistrationActions::invite()` before the `CreateAction`.

- [ ] **Step 5: Resource, pages, table, infolist**

`MemberApplicationResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\MemberApplications\Schemas\MemberApplicationInfolist;
use App\Filament\Resources\MemberApplications\Tables\MemberApplicationsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class MemberApplicationResource extends Resource
{
    protected static ?string $model = MemberApplication::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Members;

    protected static ?int $navigationSort = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'mobile';

    public static function getModelLabel(): string
    {
        return __('registration.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('registration.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return MemberApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberApplications::route('/'),
            'view' => ViewMemberApplication::route('/{record}'),
        ];
    }
}
```
(If other resources in `NavGroup::Members` set no `$navigationIcon`, drop it here too — Filament refuses icons on both a group and its items.)

`Pages/ListMemberApplications.php`: `final class ListMemberApplications extends ListRecords` with `$resource` and `getHeaderActions(): array { return [RegistrationActions::invite()]; }`.

`Pages/ViewMemberApplication.php`:

```php
final class ViewMemberApplication extends ViewRecord
{
    protected static string $resource = MemberApplicationResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['nominees.nomineeRelation', 'decisions']);
    }

    public function getTitle(): string
    {
        /** @var MemberApplication $record */
        $record = $this->record;

        return $record->name_bn ?? $record->mobile;
    }

    protected function getHeaderActions(): array
    {
        return [RegistrationActions::approve(), RegistrationActions::sendBack(), RegistrationActions::reject()];
    }
}
```

`Tables/MemberApplicationsTable.php`:

```php
final class MemberApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('mobile')->label(__('registration.field.mobile'))->searchable(),
                TextColumn::make('name_bn')->label(__('members.member.name_bn'))->placeholder('—')->searchable(),
                TextColumn::make('status')->label(__('members.member.status'))->badge(),
                TextColumn::make('current_step')
                    ->label(__('registration.field.current_step'))
                    ->state(fn (MemberApplication $record): ?string => $record->currentRole()?->getLabel())
                    ->placeholder('—'),
                TextColumn::make('submitted_at')->label(__('registration.field.submitted_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('members.member.status'))->options(MemberApplicationStatus::class),
                Filter::make('waiting_for_me')
                    ->label(__('registration.filters.waiting_for_me'))
                    ->query(fn (Builder $query): Builder => $query->waitingFor(auth()->user())),
            ])
            ->recordActions([
                ViewAction::make()->icon(Heroicon::OutlinedEye)->color('gray')->tooltip(__('registration.singular')),
            ]);
    }
}
```
(`members.member.status` already exists. For Larastan, resolve the user first: `->query(function (Builder $query): Builder { /** @var User $user */ $user = auth()->user(); return $query->waitingFor($user); })` — the staff panel always has a `User`.)

`Schemas/MemberApplicationInfolist.php`:

```php
final class MemberApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (MemberApplication $record): string => app(RegistrationTimeline::class)->headline($record))
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('timeline')
                        ->hiddenLabel()
                        ->view('filament.registration.timeline')
                        ->state(fn (MemberApplication $record): array => app(RegistrationTimeline::class)->steps($record)),
                ]),
            Section::make(__('members.member.personal_section'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    ImageEntry::make('photo_path')->hiddenLabel()->disk('local')->visibility('private')->circular()
                        ->visible(fn (MemberApplication $record): bool => $record->photo_path !== null),
                    TextEntry::make('name_bn')->label(__('members.member.name_bn'))->placeholder('—'),
                    TextEntry::make('name_en')->label(__('members.member.name_en'))->placeholder('—'),
                    TextEntry::make('guardian_name')->label(__('members.member.guardian_name'))->placeholder('—'),
                    TextEntry::make('nid')->label(__('members.member.nid'))->placeholder('—'),
                    TextEntry::make('date_of_birth')->label(__('members.member.date_of_birth'))->date()->placeholder('—'),
                    TextEntry::make('mobile')->label(__('members.member.mobile')),
                    TextEntry::make('email')->label(__('members.member.email'))->placeholder('—'),
                    TextEntry::make('requested_shares')->label(__('registration.field.requested_shares'))->placeholder('—'),
                    TextEntry::make('address')->label(__('members.member.address'))->placeholder('—')->columnSpanFull(),
                ]),
            Section::make(__('members.member.nominees_section'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('nominees')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('name')->hiddenLabel()->weight('bold'),
                            TextEntry::make('relation_id')->hiddenLabel()->state(fn (MemberApplicationNominee $record): string => $record->nomineeRelation?->label() ?? '—'),
                            TextEntry::make('nid')->hiddenLabel()->placeholder('—'),
                            TextEntry::make('mobile')->hiddenLabel()->placeholder('—'),
                            TextEntry::make('share_bps')->hiddenLabel()->state(fn (MemberApplicationNominee $record): string => $record->share()->format(app()->getLocale())),
                        ]),
                ]),
        ]);
    }
}
```

- [ ] **Step 6: Classify the new actions** — in `expectedTiers()` of `tests/Feature/ActionInventoryTest.php` add `'invite' => 'T2', 'sendBack' => 'T3',` (`approve` and `reject` are already T3). In `expectedLooks()` add `'sendBack' => [Heroicon::OutlinedArrowUturnLeft, 'warning'],`.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration tests/Feature/ActionInventoryTest.php tests/Feature/Members/MemberResourceTest.php tests/Feature/Support`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament app/Domain tests
git commit -m "Admin: invite members, review registrations, approve / send back / reject"
```

---

### Task 12: Setting the approval order

**Files:**
- Create: `app/Domain/Members/Registration/Actions/UpdateRegistrationApprovalChain.php`, `app/Filament/Clusters/Settings/Pages/RegistrationApprovalsPage.php`
- Test: `tests/Feature/Registration/RegistrationApprovalChainTest.php`

**Interfaces:**
- Consumes: `SomitiProfile::registrationApprovalChain()` (Task 3), `SubmitRegistration` snapshot (Task 6).
- Produces: `UpdateRegistrationApprovalChain::ALLOWED` (`[President, Secretary, Cashier, Accountant]`), `__invoke(User $actor, list<Role> $roles): SomitiProfile`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Registration/RegistrationApprovalChainTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Actions\UpdateRegistrationApprovalChain;
use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\RegistrationApprovalsPage;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    SomitiProfile::query()->create(['id' => SomitiProfile::ID, 'name_bn' => 'সমিতি', 'name_en' => 'Somiti']);
});

it('changes the order for new submissions and keeps it for submitted ones', function (): void {
    $before = submittedRegistration('01811111111');

    app(UpdateRegistrationApprovalChain::class)(userWithRole(Role::President), [Role::Secretary, Role::Cashier, Role::President]);
    $after = app(\App\Domain\Members\Registration\Actions\SubmitRegistration::class)(completeRegistration(invite('01822222222'), ['nid' => '1111111111']), (string) \Illuminate\Support\Str::uuid());

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
```
(`Repeater::simple()` state is a list of plain values in some Filament versions and a list of `['role' => …]` rows in others — if `fillForm` above does not take, use `['steps' => ['president']]` and read `$this->data['steps']` the same way in the page's save action.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Registration/RegistrationApprovalChainTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the Action**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Sets who approves a member's own registration, in order. Registrations already submitted keep
 * the order they were submitted with (spec §6 R9).
 */
final class UpdateRegistrationApprovalChain
{
    /** @var list<Role> committee roles that may approve a registration */
    public const array ALLOWED = [Role::President, Role::Secretary, Role::Cashier, Role::Accountant];

    public function __construct(private readonly CauserResolver $causer) {}

    /**
     * @param  list<Role>  $roles
     */
    public function __invoke(User $actor, array $roles): SomitiProfile
    {
        Gate::forUser($actor)->authorize('update', SomitiProfile::class);

        $values = array_map(fn (Role $role): string => $role->value, $roles);

        $allowed = array_map(fn (Role $role): string => $role->value, self::ALLOWED);

        if ($values === [] || count(array_unique($values)) !== count($values) || array_diff($values, $allowed) !== []) {
            throw DomainRuleViolation::because('registration.errors.chain_invalid');
        }

        return $this->causer->withCauser($actor, fn (): SomitiProfile => DB::transaction(function () use ($actor, $values): SomitiProfile {
            $profile = SomitiProfile::query()->whereKey(SomitiProfile::ID)->lockForUpdate()->first()
                ?? throw DomainRuleViolation::because('registration.errors.profile_first');

            $profile->forceFill(['registration_approval_roles' => $values, 'updated_by' => $actor->id])->save();

            return $profile;
        }, attempts: 3));
    }
}
```

- [ ] **Step 4: The settings page** — `app/Filament/Clusters/Settings/Pages/RegistrationApprovalsPage.php` (reuses the society profile page's blade, which only renders `$this->form`)

```php
<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Members\Registration\Actions\UpdateRegistrationApprovalChain;
use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Role;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
final class RegistrationApprovalsPage extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.clusters.settings.somiti-profile';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'registration-approvals';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('registration.chain.title');
    }

    public function getTitle(): string
    {
        return __('registration.chain.title');
    }

    public function getSubheading(): string
    {
        return __('registration.chain.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', SomitiProfile::class);
    }

    public function mount(): void
    {
        $this->form->fill(['steps' => array_map(fn (Role $role): array => ['role' => $role->value], SomitiProfile::current()->registrationApprovalChain())]);
    }

    public function form(Schema $schema): Schema
    {
        $options = [];

        foreach (UpdateRegistrationApprovalChain::ALLOWED as $role) {
            $options[$role->value] = $role->getLabel();
        }

        return $schema->statePath('data')->components([
            Repeater::make('steps')
                ->hiddenLabel()
                ->simple(Select::make('role')->label(__('registration.chain.role'))->options($options)->required()->native(false))
                ->reorderable()
                ->minItems(1)
                ->addActionLabel(__('registration.chain.add')),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->saveAction()];
    }

    public function saveAction(): Action
    {
        return self::tier2(
            Action::make('save')
                ->label(__('registration.actions.save_chain'))
                ->tooltip(__('registration.actions.save_chain'))
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->mountUsing(fn () => $this->form->validate())
                ->action(function (): void {
                    DomainActionRunner::run(fn (User $actor): SomitiProfile => app(UpdateRegistrationApprovalChain::class)($actor, $this->roles()));
                    Notification::make()->title(__('registration.notifications.chain_saved'))->success()->send();
                    $this->mount();
                }),
            heading: __('registration.chain.title'),
            rows: fn (): array => ChangeSummary::rows(
                ['chain' => __('registration.chain.title')],
                ['chain' => $this->describe(SomitiProfile::current()->registrationApprovalChain())],
                ['chain' => $this->describe($this->roles())],
            ),
        );
    }

    /**
     * @return list<Role>
     */
    private function roles(): array
    {
        $roles = [];

        foreach ((array) ($this->data['steps'] ?? []) as $row) {
            $value = is_array($row) ? ($row['role'] ?? null) : $row;

            if (is_string($value) && Role::tryFrom($value) !== null) {
                $roles[] = Role::from($value);
            }
        }

        return $roles;
    }

    /**
     * @param  list<Role>  $roles
     */
    private function describe(array $roles): string
    {
        return implode(' → ', array_map(fn (Role $role): string => $role->getLabel(), $roles));
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Registration tests/Feature/ActionInventoryTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Settings: registration approval order"
```

---

### Task 13: Web portal — the same journey at /portal

**Files:**
- Create: `app/Filament/Member/Concerns/ScopedToApplicant.php`, `app/Filament/Member/Pages/RegistrationStatus.php`, `app/Filament/Member/Pages/Registration.php`, `app/Http/Middleware/RedirectApplicantsToRegistration.php`, `resources/views/filament/member/registration-status.blade.php`, `resources/views/filament/member/registration.blade.php`
- Modify: `app/Models/User.php` (`canAccessPanel`), `app/Filament/Member/Concerns/ScopedToMember.php` (`canAccess`), `app/Providers/Filament/MemberPanelProvider.php` (auth middleware)
- Test: `tests/Feature/Portal/PortalRegistrationTest.php`

**Interfaces:**
- Consumes: `AccountTypes` (Task 9), `SaveRegistrationDraft`, `RegistrationDraft`, `SubmitRegistration`, `RegistrationTimeline`, `filament.registration.timeline` (Tasks 5-8).
- Produces: portal routes `filament.member.pages.registration-status`, `filament.member.pages.registration`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Portal/PortalRegistrationTest.php`

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Filament\Member\Pages\Auth\MemberLogin;
use App\Filament\Member\Pages\Dashboard;
use App\Filament\Member\Pages\Registration;
use App\Filament\Member\Pages\RegistrationStatus;
use Filament\Facades\Filament;
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
    $this->actingAs($this->application->user);

    $this->get('/portal')->assertRedirect(RegistrationStatus::getUrl());
    $this->get('/portal/dues')->assertRedirect(RegistrationStatus::getUrl());
    $this->get(RegistrationStatus::getUrl())->assertOk()->assertSee('Your registration is incomplete');
});

it('saves each wizard step and submits after the summary', function (): void {
    userWithRole(\App\Enums\Role::Secretary);
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

it('lets an approved member into the normal portal and out of the registration pages', function (): void {
    approveRegistration(submittedRegistrationFrom($this->application));
    $this->actingAs($this->application->user->fresh());

    $this->get('/portal')->assertOk();
    $this->get(RegistrationStatus::getUrl())->assertForbidden();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact tests/Feature/Portal/PortalRegistrationTest.php`
Expected: FAIL — `RegistrationStatus` not found.

- [ ] **Step 3: Access rules**

`User::canAccessPanel` — member branch:

```php
        if ($panel->getId() === 'member') {
            return ! $this->isStaff() && app(AccountTypes::class)->of($this) !== null;
        }
```

`ScopedToMember` — add:

```php
    /**
     * Member pages (and their menu items) exist only for members, not for people still registering.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PortalAccounts::class)->activeMemberOf($user) !== null;
    }
```

`app/Filament/Member/Concerns/ScopedToApplicant.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Member\Concerns;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;

/**
 * Registration pages exist only for people still registering, and show only their own registration.
 */
trait ScopedToApplicant
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AccountTypes::class)->of($user) === AccountType::Applicant;
    }

    protected static function application(): MemberApplication
    {
        $user = auth()->user();
        $application = $user instanceof User ? app(AccountTypes::class)->openApplicationOf($user) : null;

        abort_if($application === null, 403);

        return $application;
    }
}
```

`app/Http/Middleware/RedirectApplicantsToRegistration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Filament\Member\Pages\Registration;
use App\Filament\Member\Pages\RegistrationStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone still registering lands on their registration status whatever portal page they open.
 */
final class RedirectApplicantsToRegistration
{
    public function __construct(private readonly AccountTypes $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->accounts->of($user) === AccountType::Applicant) {
            $allowed = [RegistrationStatus::getRouteName(), Registration::getRouteName(), 'filament.member.auth.logout'];

            if (! in_array($request->route()?->getName(), $allowed, true)) {
                return redirect(RegistrationStatus::getUrl());
            }
        }

        return $next($request);
    }
}
```

`MemberPanelProvider`: `->authMiddleware([Authenticate::class, RedirectApplicantsToRegistration::class])`.

- [ ] **Step 4: Status page** — `RegistrationStatus.php` + blade

```php
<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Registration\Services\RegistrationTimeline;
use App\Filament\Member\Concerns\ScopedToApplicant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class RegistrationStatus extends Page
{
    use ScopedToApplicant;

    protected string $view = 'filament.member.registration-status';

    protected static ?string $slug = 'registration-status';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getNavigationLabel(): string
    {
        return __('registration.portal.nav');
    }

    public function getTitle(): string
    {
        return __('registration.portal.status_title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(fn (): string => self::application()->nextAction()->getLabel())
                ->tooltip(__('registration.actions.edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->url(fn (): string => Registration::getUrl())
                ->visible(fn (): bool => self::application()->status->isEditable()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $application = self::application();
        $timeline = app(RegistrationTimeline::class);

        return [
            'headline' => $timeline->headline($application),
            'message' => $timeline->message($application),
            'decision' => $timeline->decision($application),
            'steps' => $timeline->steps($application),
        ];
    }
}
```

`resources/views/filament/member/registration-status.blade.php`:

```blade
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $headline }}</x-slot>
        <p>{{ $message }}</p>
        @if ($decision)
            <p class="mt-3 text-sm">
                <span class="font-medium">{{ __('registration.field.reason') }}:</span> {{ $decision->reason }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section>
        @include('filament.registration.timeline', ['steps' => $steps])
    </x-filament::section>
</x-filament-panels::page>
```

- [ ] **Step 5: Registration wizard** — `Registration.php` + blade

```php
<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationNominee;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Member\Concerns\ScopedToApplicant;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Bps;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The member's own registration form on the web: the same five steps as the app. Each step is
 * saved as a draft when the member moves on; submitting shows a summary first (T2).
 *
 * @property-read Schema $form
 */
final class Registration extends Page
{
    use ConfirmsWithTier, ScopedToApplicant;

    protected string $view = 'filament.member.registration';

    protected static ?string $slug = 'registration';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public array $data = [];

    /** One key per opening of the form: a double submit or a retry submits once. */
    public string $idempotencyKey = '';

    public function getTitle(): string
    {
        return __('registration.portal.form_title');
    }

    public function mount(): void
    {
        $application = self::application()->load('nominees');

        if (! $application->status->isEditable()) {
            $this->redirect(RegistrationStatus::getUrl());

            return;
        }

        $this->idempotencyKey = (string) Str::uuid();
        $nominees = $application->nominees->map(fn (MemberApplicationNominee $nominee): array => [
            'name' => $nominee->name,
            'relation_id' => $nominee->relation_id,
            'nid' => $nominee->nid,
            'mobile' => $nominee->mobile,
            'share_percent' => $nominee->share()->toPercentString(),
        ])->values()->all();

        $this->form->fill([
            ...$application->only(['name_bn', 'name_en', 'guardian_name', 'nid', 'email', 'address', 'photo_path', 'requested_shares']),
            'date_of_birth' => $application->date_of_birth?->toDateString(),
            'nominees' => $nominees === [] ? [['share_percent' => '100']] : $nominees,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make(__('registration.steps.personal'))
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(2)
                    ->afterValidation(fn () => $this->saveDraft())
                    ->schema([
                        TextInput::make('name_bn')->label(__('members.member.name_bn'))->required()->maxLength(255),
                        TextInput::make('name_en')->label(__('members.member.name_en'))->required()->maxLength(255),
                        TextInput::make('guardian_name')->label(__('members.member.guardian_name'))->maxLength(255),
                        TextInput::make('nid')->label(__('members.member.nid'))->helperText(__('members.member.nid_help'))->regex('/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u'),
                        DatePicker::make('date_of_birth')->label(__('members.member.date_of_birth'))->native(false)->maxDate(now()),
                        FileUpload::make('photo_path')->label(__('registration.field.photo'))->image()->avatar()->disk('local')->directory('member-photos')->visibility('private')->maxSize(1024),
                    ]),
                Step::make(__('registration.steps.contact'))
                    ->icon(Heroicon::OutlinedPhone)
                    ->columns(2)
                    ->afterValidation(fn () => $this->saveDraft())
                    ->schema([
                        TextEntry::make('mobile')->label(__('members.member.mobile'))->state(fn (): string => self::application()->mobile),
                        TextInput::make('email')->label(__('members.member.email'))->email(),
                        Textarea::make('address')->label(__('members.member.address'))->rows(2)->columnSpanFull(),
                    ]),
                Step::make(__('registration.steps.nominees'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->afterValidation(fn () => $this->saveDraft())
                    ->schema([
                        Repeater::make('nominees')
                            ->hiddenLabel()
                            ->addActionLabel(__('members.nominee.add'))
                            ->minItems(1)
                            ->columns(['default' => 1, 'md' => 5])
                            ->schema([
                                TextInput::make('name')->label(__('members.nominee.name'))->required(),
                                Select::make('relation_id')->label(__('members.nominee.relation'))->options(fn (): array => NomineeRelation::options())->required()->native(false),
                                TextInput::make('nid')->label(__('members.nominee.nid'))->required()->regex('/^([0-9০-৯]{10}|[0-9০-৯]{13}|[0-9০-৯]{17})$/u'),
                                TextInput::make('mobile')->label(__('members.nominee.mobile'))->tel(),
                                TextInput::make('share_percent')->label(__('members.nominee.share'))->suffix('%')->required()->live(onBlur: true),
                            ]),
                        TextEntry::make('nominee_total')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => __('registration.portal.nominee_total', ['total' => self::nomineeTotal($get('nominees'))->format(app()->getLocale())])),
                    ]),
                Step::make(__('registration.steps.shares'))
                    ->icon(Heroicon::OutlinedSquare3Stack3d)
                    ->afterValidation(fn () => $this->saveDraft())
                    ->schema([
                        TextInput::make('requested_shares')->label(__('registration.field.requested_shares'))->integer()->minValue(1)->required(),
                    ]),
                Step::make(__('registration.steps.review'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->schema([
                        TextEntry::make('review')
                            ->hiddenLabel()
                            ->state(fn (): HtmlString => new HtmlString(ChangeSummary::view($this->summaryRows(), showOld: false)->render())),
                    ]),
            ])->submitAction(new HtmlString('<x-filament::button type="submit">'.e(__('registration.actions.submit')).'</x-filament::button>')),
        ]);
    }

    public function submit(): void
    {
        $this->mountAction('submit');
    }

    public function submitAction(): Action
    {
        return self::tier2(
            Action::make('submit')
                ->label(__('registration.actions.submit'))
                ->tooltip(__('registration.actions.submit'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->action(function (): void {
                    $this->saveDraft($this->form->getState());
                    DomainActionRunner::run(fn (User $actor): MemberApplication => app(SubmitRegistration::class)(self::application(), $this->idempotencyKey));
                    $this->redirect(RegistrationStatus::getUrl());
                }),
            heading: __('registration.actions.submit_heading'),
            rows: fn (): array => $this->summaryRows(),
            description: __('registration.actions.submit_description'),
            showOld: false,
        );
    }

    /**
     * @param  array<string, mixed>|null  $state  validated state (submit) or the raw step data
     */
    public function saveDraft(?array $state = null): void
    {
        $input = $state ?? $this->data;

        if (! is_string($input['photo_path'] ?? null)) {
            unset($input['photo_path']); // an upload still in progress is saved at submit
        }

        DomainActionRunner::run(fn (User $actor): MemberApplication => app(SaveRegistrationDraft::class)(self::application(), RegistrationDraft::fromInput($input)));
    }

    /**
     * @return list<array{label: string, old: string, new: string}>
     */
    private function summaryRows(): array
    {
        $nominees = collect((array) ($this->data['nominees'] ?? []))
            ->filter(fn (mixed $row): bool => is_array($row) && trim((string) ($row['name'] ?? '')) !== '')
            ->map(fn (array $row): string => sprintf('%s (%s%%)', $row['name'], $row['share_percent'] ?? '0'))
            ->implode(', ');

        return ChangeSummary::rows([
            'name_bn' => __('members.member.name_bn'),
            'name_en' => __('members.member.name_en'),
            'guardian_name' => __('members.member.guardian_name'),
            'nid' => __('members.member.nid'),
            'date_of_birth' => __('members.member.date_of_birth'),
            'address' => __('members.member.address'),
            'requested_shares' => __('registration.field.requested_shares'),
            'nominees' => __('members.member.nominees_section'),
        ], [], [...$this->data, 'nominees' => $nominees]);
    }

    private static function nomineeTotal(mixed $rows): Bps
    {
        $total = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            try {
                $total += Bps::ofPercent((string) (is_array($row) ? ($row['share_percent'] ?? '') : ''))->value;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return Bps::of($total);
    }
}
```

`resources/views/filament/member/registration.blade.php`:

```blade
<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Portal tests/Feature/ActionInventoryTest.php`
Expected: PASS (`PortalTest` and `PortalPasswordTest` unchanged).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources tests
git commit -m "Portal: registration wizard and status for invited members"
```

---

### Task 14: Final checks and spec cross-reference

**Files:**
- Modify: `SOMITI_SPEC.md` (§1.6, after W1), `docs/superpowers/specs/2026-10-07-member-self-registration-design.md` (status line)

- [ ] **Step 1: Note the new workflow in the project spec** — add after W1 in `SOMITI_SPEC.md` §1.6:

```markdown
**W1a Self-registration.** The secretary (or anyone with `members.create`) invites a member with
only a mobile and a password. The member signs in on the app or `/portal`, fills in their details
and nominees (at least one, NID required, relation from the managed list), and submits. The
registration passes the approval order set in Settings (default Secretary → President); approvers
can approve, send it back for correction or reject it. The last approval runs W1 (member number,
share lot, registration fee, welcome SMS). Design: `docs/superpowers/specs/2026-10-07-member-self-registration-design.md`.
```
and set the design doc's status line to `Status: approved, implemented`.

- [ ] **Step 2: Full verification**

Run: `composer test`
Expected: all green.

Run: `composer analyse`
Expected: `[OK] No errors` (Larastan level 8, no baseline). Fix any missing generics/array shapes in the new files — do not add a baseline.

Run: `composer format:check`
Expected: no changes needed.

- [ ] **Step 3: Manual end-to-end** (local, `composer run dev`)

1. `/admin` as the secretary → Members → **Invite member** `01811111111` / `secret-123`.
2. Private window → `/portal` → sign in with that mobile/password → lands on **Registration status** ("Your registration is incomplete").
3. Fill the five steps, submit, confirm the summary → status shows "Secretary approval pending".
4. `/admin` → Registrations → open it → **Approve** (type the mobile) → as the president approve with shares + month.
5. Portal window → reload → normal member dashboard with the new member number.

- [ ] **Step 4: Commit**

```bash
git add SOMITI_SPEC.md docs/superpowers/specs/2026-10-07-member-self-registration-design.md
git commit -m "Docs: self-registration workflow (W1a)"
```
