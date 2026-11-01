# Registration Matches the Paper Form — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Member registration (office form, portal, mobile API) captures everything on the paper সদস্য নিবন্ধন ফর্ম, and every member's agreement to a versioned copy of the somiti's rules is recorded, with re-acceptance when the rules change.

**Architecture:** A read-only `Geo` module loads Bangladesh divisions → districts → upazilas → unions from the CSVs once by migration and serves them to searchable chained Filament selects and to public API lookups. New member/nominee fields flow through the existing `MemberData` / `NomineeData` / `RegistrationDraft` DTOs and `MemberRules`. A `Members/Terms` sub-module stores published rule versions (immutable) and insert-only acceptances; a `TermsGate` service drives a portal middleware and an API middleware that block members until they accept the current version.

**Tech Stack:** Laravel 12 / PHP 8.5, Filament 4, PostgreSQL, Pest, Larastan level 8, Pint. Mobile: Flutter (GetX, Dio) in the sibling repo `somobay_somiti_mobile_app` (Tasks 12–15).

**Spec:** `docs/superpowers/specs/2026-11-01-registration-matches-paper-design.md` (read it first). Source paper: `docs/manual-page/রয়েল ডিস্ট্রিক্ট মৈত্রী সমিতি (21 x 29.7 cm).pdf`.

## Global Constraints
- Follow `CLAUDE.md`: Actions are final invokable classes, `DB::transaction(..., attempts: 3)`, `lockForUpdate()`, activity log, `DomainRuleViolation::because('<bn/en key>')`.
- DTOs `final readonly class`; enums backed + `HasLabel`, `HasColor`, `HasIcon` (copy `app/Domain/Members/Enums/MemberStatus.php`).
- Every user-facing string via `__()` in both `lang/bn` and `lang/en` (the bn/en parity test `LocalizationTest.php` fails otherwise).
- Filament write actions use `ConfirmsWithTier` (`tier1/tier2/tier3`) and `->action()`; publish rules = **T3**.
- Policies on every new model; no inline role checks in UI.
- Forward-only migrations; new member/nominee columns **nullable** (existing rows stay valid).
- After every task: `vendor/bin/pint --dirty --format agent`, the task's tests, then `composer analyse`.
- Geo CSVs: no header row, CRLF line endings, website column may be the literal string `"null"`. Row counts: divisions **8**, districts **64**, upazilas **576**, unions **4855**. Read with `fgetcsv($h, 0, ',', '"', '\\')`.
- **Address shape (refines the spec, which said union only):** 5 upazilas have no unions (e.g. Guimara/Khagrachhari, Dasar/Madaripur), so an address is stored as `{prefix}_upazila_id` (required at submit) + `{prefix}_union_id` (required only when that upazila has unions, and must belong to it) + `{prefix}_address_line` (required at submit, ≤ 255). Division/district are derived from the upazila. Update the spec's §1 bullet in Task 1's commit.
- "Required at submit" = when a member is **created** (office create, self-registration submit). `UpdateMember` on an existing member checks formats only, so legacy members can still be edited with blanks.
- Gender values: `male`, `female`, `other`.

## Review Focus
1. **Search by Bangla or English, typed partially** ("ধানমন্" / "dhanm") → the place appears; duplicate names (two "Mirpur" upazilas) never cross parents because each list is filtered by its parent. → Task 2 test `geo search matches bangla and english prefixes`.
2. **Changing the parent after picking a child** (district changed after upazila chosen) → child and grandchild cleared; a stale union from another upazila is rejected server-side. → Task 4 test `rejects a union outside the chosen upazila`; Task 8 Livewire test `clears upazila and union when district changes`.
3. **Upazila with no unions** → can submit with union empty. → Task 4 test `accepts an address without union when the upazila has none`.
4. **Rules republished while a member is mid-registration** → submit with the old version is refused with `terms.errors.outdated`; the portal shows the new text. → Task 6 test `refuses acceptance of a superseded version`.
5. **Existing member on next login / app call** → portal redirects to accept page; API returns 403 with `meta.code = terms_acceptance_required`, but `GET terms/current`, `POST terms/accept`, `auth/logout`, `auth/me` still work. → Task 7 tests.

---

### Task 1: Geo tables, CSV load, models, lookup service

**Files:**
- Create: `database/migrations/2026_11_01_000001_create_geo_tables.php`
- Create: `app/Domain/Geo/Models/{GeoDivision,GeoDistrict,GeoUpazila,GeoUnion}.php`
- Create: `app/Domain/Geo/Services/GeoDirectory.php`
- Create: `app/Domain/Geo/Services/GeoCsvLoader.php`
- Modify: `docs/superpowers/specs/2026-11-01-registration-matches-paper-design.md` (address shape)
- Test: `tests/Feature/Geo/GeoDataTest.php`

**Interfaces:**
- Produces: tables `geo_divisions(id, name_en, name_bn)`, `geo_districts(id, division_id, …)`, `geo_upazilas(id, district_id, …)`, `geo_unions(id, upazila_id, …)`; ids = CSV ids (no sequence). Models read-only (`ImmutableRecord`-style guard on save/delete outside the loader is not needed — no write UI; just no policy write abilities).
- `GeoCsvLoader::load(string $directory): void` — upserts all four files by id, parents first. Migration calls it with `database_path('demrcation')`.
- `GeoDirectory`:
  - `divisions(?string $search = null): array<int, string>`
  - `districts(int $divisionId, ?string $search = null): array<int, string>`
  - `upazilas(int $districtId, ?string $search = null): array<int, string>`
  - `unions(int $upazilaId, ?string $search = null): array<int, string>`
  - `upazilaHasUnions(int $upazilaId): bool`
  - `unionBelongsTo(int $unionId, int $upazilaId): bool`
  - `describe(int $upazilaId, ?int $unionId): string` — "ইউনিয়ন, উপজেলা, জেলা, বিভাগ" in the current locale
  - `chainFor(int $upazilaId): array{division_id:int, district_id:int}`
  - Labels in current locale (`bn` → `name_bn`, else `name_en`); search is case-insensitive `ILIKE '%term%'` on **both** names; ordered by label.

- [ ] **Step 1: Write failing tests** — `loads every place from the csv files` (counts 8/64/576/4855; `GeoUnion::find(4541)->name_bn === 'গুলশান-১'`); `reloading is idempotent` (call `GeoCsvLoader::load` again → same counts); `lists children of a parent only` (`districts(6)` contains 47 => ঢাকা and nothing from another division); `knows upazilas without unions` (Guimara → false; Savar id 365 → true); `describe builds the full place name`.
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Geo/GeoDataTest.php` → FAIL.
- [ ] **Step 3:** Migration + models + services. FKs `restrictOnDelete`; index each parent FK.
- [ ] **Step 4:** Tests PASS; `composer analyse` clean.
- [ ] **Step 5: Commit** — `git add database/demrcation database/migrations app/Domain/Geo tests/Feature/Geo docs/superpowers/specs && git commit -m "Geo: Bangladesh divisions to unions, loaded from CSV"` (this also commits the user's staged CSVs).

### Task 2: Geo lookup API for the mobile app

**Files:**
- Create: `app/Http/Controllers/Api/GeoController.php`
- Modify: `routes/api.php` (public group under `v1`, next to `config/nominee-relations`, `throttle:member-api`)
- Test: `tests/Feature/Api/ApiGeoTest.php`

**Interfaces:**
- Consumes: `GeoDirectory`.
- Produces: `GET /api/v1/geo/divisions`, `GET /api/v1/geo/districts?division_id=&q=`, `GET /api/v1/geo/upazilas?district_id=&q=`, `GET /api/v1/geo/unions?upazila_id=&q=`. Response `ApiResponse::ok([{id, name_bn, name_en}, …])` (both names regardless of locale). Missing/invalid parent id → 422 via `ApiResponse::error(__('api.errors.validation'), 422, errors)`.

- [ ] **Step 1: Failing tests** — `lists divisions`; `filters districts by division`; `geo search matches bangla and english prefixes` (`unions?upazila_id=495&q=গুলশান` and `q=gulshan` both return 4541, 4542); `requires the parent id` (422).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5: Commit** — "API: geo lookups for registration dropdowns".

### Task 3: New enums and columns

**Files:**
- Create: `app/Domain/Members/Enums/Gender.php` (`male`, `female`, `other`; labels `members.gender.*`; colors info/pink/gray; icons any `Heroicon::Outlined*User*`)
- Create: `app/Domain/Members/Enums/RegistrationDocument.php` (`nid`, `passport`, `nominee_nid`, `nominee_photo`; labels `members.documents.*`)
- Create: `database/migrations/2026_11_01_000002_add_paper_form_fields.php`
- Modify: models `Member`, `Nominee`, `MemberApplication`, `MemberApplicationNominee` (fillable/casts: `gender` → `Gender`, `documents_received` → `AsEnumCollection` of `RegistrationDocument` or `array`)
- Test: `tests/Feature/Members/PaperFormSchemaTest.php`

**Interfaces:**
- Columns on `members` and `member_applications`: `gender` string(6) null, `passport_no` string(20) null, `present_upazila_id`, `present_union_id`, `permanent_upazila_id`, `permanent_union_id` (FK geo, null), `present_address_line`, `permanent_address_line` string(255) null, `documents_received` jsonb default `'[]'`.
- Same on `nominees` and `member_application_nominees` **except** `documents_received`.
- CHECK constraints: `gender IS NULL OR gender IN ('male','female','other')`; `passport_no IS NULL OR passport_no ~ '^[A-Z0-9]{6,20}$'`.

- [ ] **Step 1: Failing test** — `stores and casts the new fields` (create member via factory with `gender => Gender::Female`, read back enum); `rejects an unknown gender at the database` (raw update → `QueryException`).
- [ ] **Steps 2–4:** run FAIL → implement → PASS.
- [ ] **Step 5: Commit** — "Members: gender, passport, structured addresses, documents received".

### Task 4: DTOs and rules carry the new fields

**Files:**
- Create: `app/Domain/Geo/Data/PostalAddress.php`
- Create: `app/Domain/Geo/Services/AddressRules.php`
- Modify: `app/Domain/Members/Data/MemberData.php`, `NomineeData.php`
- Modify: `app/Domain/Members/Services/MemberRules.php`, `NomineeRules.php`, `NomineeWriter.php`
- Modify: `app/Domain/Members/Registration/Data/RegistrationDraft.php`, `Actions/SaveRegistrationDraft.php`, `Models/MemberApplication.php` (`toMemberData`)
- Modify: `tests/Pest.php` helpers `nominee()`, `memberData()`, `registrationInput()` to include complete paper-form data by default
- Test: `tests/Feature/Members/PaperFormRulesTest.php`

**Interfaces:**
- `final readonly class PostalAddress { __construct(public int $upazilaId, public ?int $unionId, public string $line) ; static fromForm(array $data, string $prefix): ?self }` — form keys `{prefix}_upazila_id`, `{prefix}_union_id`, `{prefix}_address_line`; returns null when upazila and line are both empty. `toAttributes(string $prefix): array<string, int|string|null>`.
- `AddressRules::assertValid(?PostalAddress $address, bool $required, string $whoseKey): void` — `$whoseKey` is a translation key for the label (e.g. `members.address.present`, nominee name passed through replace). Errors: `members.errors.address_required`, `members.errors.address_union_required`, `members.errors.address_union_mismatch`, `members.errors.address_line_too_long`.
- `MemberData` new props (all defaulted, after `nominees` is fine but keep named args): `?Gender $gender = null`, `?string $passportNo = null`, `?PostalAddress $presentAddress = null`, `?PostalAddress $permanentAddress = null`, `list<RegistrationDocument> $documentsReceived = []`. `fromForm` reads `gender`, `passport_no` (uppercased, spaces stripped), addresses, `documents_received`; when `permanent_same_as_present` is truthy, permanent = present. `toAttributes()` includes all new columns.
- `NomineeData` new props: `?Gender $gender`, `?string $passportNo`, `?PostalAddress $presentAddress`, `?PostalAddress $permanentAddress`; same form keys and "same as present" flag per row.
- `MemberRules::assertValid(MemberData $data, ?Member $existing = null, ?int $ignoreApplicationId = null)` — when `$existing === null` (new member) require gender (`members.errors.gender_required`), both addresses; always run format checks (passport `members.errors.passport_format`, address union mismatch). Passes `required: $existing === null` to `NomineeRules::assertValid(array $nominees, bool $requireFullProfile = true)` which requires nominee gender (`members.errors.nominee_gender_required`) and both addresses.
- `RegistrationDraft::FIELDS` adds the new member keys; nominee rows keep using `NomineeData::fromForm`. `SaveRegistrationDraft` writes new nominee columns; `MemberApplication::toMemberData` maps them back. `NomineeWriter::replace` writes them.

- [ ] **Step 1: Failing tests** (dataset style like `SubmitRegistrationTest` "runs the member rules"):
  - new member without gender → `members.errors.gender_required`
  - without present address → `members.errors.address_required`
  - `rejects a union outside the chosen upazila` → `address_union_mismatch`
  - `accepts an address without union when the upazila has none` (Guimara)
  - union missing where upazila has unions → `address_union_required`
  - nominee without gender / address → nominee keys
  - `same as present copies the address` (fromForm)
  - `updating a legacy member does not demand the new fields` (`UpdateMember` on a member with nulls, unchanged data → no exception)
  - `approval copies the new fields to the member and nominees` (full `submittedRegistration` → decide → member has gender, addresses, nominee addresses)
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** run `tests/Feature/Members tests/Feature/Registration tests/Feature/Api tests/Concurrency` → PASS (helpers updated so older tests stay green).
- [ ] **Step 5: Commit** — "Members: paper-form fields required for new members and nominees".

### Task 5: Rules versions (terms) storage, seed v1, publish

**Files:**
- Create: `database/migrations/2026_11_01_000003_create_membership_terms_tables.php`
- Create: `app/Domain/Members/Terms/Models/{MembershipTerms,TermsAcceptance}.php`
- Create: `app/Domain/Members/Terms/Enums/{TermsStatus,ConsentChannel}.php` (`draft|published`; `portal|app|office`)
- Create: `app/Domain/Members/Terms/Actions/{SaveTermsDraft,PublishTerms}.php`
- Create: `app/Policies/MembershipTermsPolicy.php`; Modify `app/Enums/Permission.php` (+ `TermsPublish = 'terms.publish'`, default roles President, Secretary; area as members); `lang/*/permissions.php` labels
- Create: `database/data/membership_terms_v1.php` returning `['rules_bn' => …, 'rules_en' => …, 'declaration_bn' => …, 'declaration_en' => …]`
- Test: `tests/Feature/Terms/MembershipTermsTest.php`

**Interfaces:**
- `membership_terms(id, version uint unique null-while-draft, status, rules_bn text, rules_en text, declaration_bn text, declaration_en text, published_at, published_by FK users, created_by, timestamps)`. Partial unique index: one `draft` at a time. Trigger forbids UPDATE/DELETE when `OLD.status = 'published'` (pattern: rate plans in `2026_10_04_000006_add_resolution_to_rate_plans.php`); model guard throws `ImmutableRecord`.
- `terms_acceptances(id, terms_id FK, member_id FK null, application_id FK null, user_id FK, channel, ip string(45) null, idempotency_key uuid unique, accepted_at timestampTz)`; CHECK exactly one of member_id/application_id; unique `(terms_id, member_id)` and `(terms_id, application_id)` partial; trigger `append_only_guard()`.
- Migration inserts v1 **published** from `database/data/membership_terms_v1.php` with `published_by` null (system). Bangla rules = the 24 numbered rules from the PDF, verbatim (re-type from the rendered PDF page images; the extracted text layer is broken). Declaration bn = the চুক্তিপত্র paragraph verbatim. English = faithful translation; add a comment at the top of the file: "English drafted by the developer — to be checked by the committee."
- `MembershipTerms::current(): self` (latest published; throws if none).
- `SaveTermsDraft(User $actor, string $rulesBn, string $rulesEn, string $declarationBn, string $declarationEn): MembershipTerms` — creates or updates the single draft; all four non-empty (`terms.errors.text_required`).
- `PublishTerms(User $actor, MembershipTerms $draft): MembershipTerms` — authorize `publish`; lock; version = max+1; status published; activity log `terms_published`.

- [ ] **Step 1: Failing tests** — `seeds version 1 as current` (`current()->version === 1`, `rules_bn` contains `'২৪.'`); `publishing a draft makes it current with the next version`; `published terms cannot change` (update → `ImmutableRecord`; raw SQL update → `QueryException`); `only one draft at a time`; `needs the publish permission` (cashier → `AuthorizationException`); `acceptances are insert-only` (raw update → `QueryException`).
- [ ] **Steps 2–4.** **Step 5: Commit** — "Terms: versioned somiti rules with v1 from the paper form".

### Task 6: Accepting the rules (self-registration + office create)

**Files:**
- Create: `app/Domain/Members/Terms/Data/TermsConsent.php`
- Create: `app/Domain/Members/Terms/Actions/AcceptTerms.php`
- Create: `app/Domain/Members/Terms/Services/TermsGate.php`
- Modify: `app/Domain/Members/Registration/Actions/SubmitRegistration.php`, `app/Domain/Members/Actions/CreateMember.php`
- Modify: `tests/Pest.php` (`submittedRegistration()` and any direct `SubmitRegistration` calls pass a consent), `tests/Concurrency/RegistrationSubmitTest.php`
- Test: `tests/Feature/Terms/AcceptTermsTest.php`

**Interfaces:**
- `final readonly class TermsConsent { __construct(public int $version, public ConsentChannel $channel, public string $idempotencyKey, public ?string $ip = null) }`
- `TermsGate::needsAcceptance(Member $member): bool` — false iff an acceptance for `MembershipTerms::current()` exists with `member_id = member` **or** `application_id` of the application whose `member_id = member` (no copying on approval; acceptances stay insert-only).
- `TermsGate::assertCurrent(int $version): MembershipTerms` — throws `terms.errors.outdated` when `$version !== current()->version`.
- `AcceptTerms(User $actor, Member $member, TermsConsent $consent): TermsAcceptance` — gate check, idempotent on key (same key → existing row), already accepted current → returns existing row.
- `SubmitRegistration::__invoke(MemberApplication $application, string $idempotencyKey, TermsConsent $consent)` — inside the transaction after the member rules: `assertCurrent`, insert acceptance with `application_id` (skip if the same key already submitted — existing idempotent return path happens first).
- `CreateMember::__invoke(User $actor, MemberData $data, int $shares, YearMonth $effectiveFrom, ?User $portalUser = null, ?TermsConsent $consent = null)` — when `$consent` given, inserts acceptance with `member_id`, `user_id = $actor->id`, inside the same transaction. `DecideRegistration` keeps passing none.

- [ ] **Step 1: Failing tests** — `submitting records the applicant's acceptance`; `refuses acceptance of a superseded version` (publish v2 then submit with 1 → `terms.errors.outdated`, status unchanged); `approved member counts as having accepted through the application` (`needsAcceptance` false); `office create records an office acceptance` (channel office, user = secretary); `existing member without acceptance needs to accept`; `publishing v2 makes everyone need to accept again`; `accepting twice with the same key stores one row`.
- [ ] **Steps 2–4** (also run `tests/Feature/Registration tests/Concurrency`). **Step 5: Commit** — "Terms: record acceptance at registration and office entry".

### Task 7: Re-acceptance gate — portal and API

**Files:**
- Create: `app/Http/Middleware/RequireCurrentTermsInPortal.php`, `app/Http/Middleware/RequireCurrentTermsInApi.php`
- Create: `app/Filament/Member/Pages/AcceptRules.php` (+ view) — shows `rules_*` and `declaration_*` in the current locale, a required "আমি পড়েছি ও সম্মত" checkbox, accept button (`->action()`, T1) calling `AcceptTerms` with channel `portal`, then redirect to Dashboard.
- Create: `app/Http/Controllers/Api/Member/TermsController.php`
- Modify: `app/Providers/Filament/MemberPanelProvider.php` (add portal middleware to `authMiddleware` after `RedirectApplicantsToRegistration`), `bootstrap/app.php` (alias `terms`), `routes/api.php`
- Modify: `app/Filament/Member/Pages/Profile.php` — link/section "Rules I accepted (version N, date)"
- Test: `tests/Feature/Terms/TermsGateHttpTest.php`

**Interfaces:**
- Portal middleware: member with `needsAcceptance` → redirect to `AcceptRules::getUrl()` except on the AcceptRules route and `filament.member.auth.logout`; applicants untouched.
- API: `GET /api/v1/terms/current` (group `ability:member,applicant`) → `{version, rules_bn, rules_en, declaration_bn, declaration_en, accepted: bool|null}`; `POST /api/v1/terms/accept` body `{version:int, idempotency_key:uuid}` (member group, **without** `terms`) → `AcceptTerms` channel `app`, ip = request ip.
- `RequireCurrentTermsInApi` added as `terms` to the member route group (after `member`): when `needsAcceptance` → `ApiResponse::error(__('terms.errors.acceptance_required'), 403, null, ['code' => 'terms_acceptance_required', 'version' => (string) $current->version])`.
- `GET /api/v1/auth/me` adds `terms_acceptance_required: bool` and `terms_version: int` (members; `false` for applicants) so the app can route at start-up without a failing call.

- [ ] **Step 1: Failing tests** — `me reports whether the rules must be accepted`; `portal sends a member who has not accepted to the rules page`; `accepting in the portal lets the member through`; `api blocks member routes until accepted` (`GET dashboard/summary` → 403, `meta.code`); `api still allows terms, logout and me`; `api accept with a stale version is refused` (422 or 409 per existing DomainRuleViolation handler); `applicants are not gated` (registration endpoints work).
- [ ] **Steps 2–4** (run `tests/Feature/Api tests/Feature/Portal` too — existing member API tests need their member to have accepted: add acceptance in the shared API test helper in `tests/Pest.php`). **Step 5: Commit** — "Terms: ask existing members to accept the current rules".

### Task 8: Shared address fields + admin member form + infolists

**Files:**
- Create: `app/Filament/Forms/AddressFields.php` — `AddressFields::make(string $prefix, string $label, bool $required): array<Component>` returning a `Fieldset` with `{prefix}_division_id` (dehydrated false), `{prefix}_district_id` (dehydrated false), `{prefix}_upazila_id`, `{prefix}_union_id`, `{prefix}_address_line`; each `Select` is `searchable()`, `->live()`, `getSearchResultsUsing` / `options` from `GeoDirectory`, `afterStateUpdated` clears descendants; union `required` only when `GeoDirectory::upazilaHasUnions`. `afterStateHydrated` on division/district fills them from `chainFor($upazilaId)` when editing.
- Create: `app/Filament/Forms/PersonFields.php` — `gender` (`Radio`/`ToggleButtons` inline from `Gender`), `passport_no`, `permanent_same_as_present` toggle that hides the permanent block.
- Modify: `app/Filament/Resources/Members/Schemas/MemberForm.php` (contact section → present + permanent address; nominee repeater rows get gender, passport, both addresses; new section "Documents received" `CheckboxList` from `RegistrationDocument`; on **create only** a required `Checkbox::make('terms_accepted')` labelled with `__('terms.office_confirm', ['version' => …])`), `fillFrom()` maps new columns.
- Modify: `app/Filament/Resources/Members/Pages/CreateMember.php` `handleRecordCreation` passes `new TermsConsent(current version, ConsentChannel::Office, Str::uuid())`.
- Modify: `app/Filament/Resources/Members/Schemas/MemberInfolist.php`, `app/Filament/Resources/MemberApplications/Schemas/MemberApplicationInfolist.php` — gender, passport, addresses via `GeoDirectory::describe`, documents, nominee details; old `address` shown labelled "Old address" only when the new present address is empty.
- Create: `app/Domain/Members/Registration/Actions/RecordApplicationDocuments.php` `(User $actor, MemberApplication $application, list<RegistrationDocument> $documents): MemberApplication` (permission `members.create`, open applications only) + T1 header action on `ViewMemberApplication`.
- Test: `tests/Feature/Members/MemberFormPaperFieldsTest.php`

- [ ] **Step 1: Failing Livewire tests** — `office create saves gender, addresses, nominee addresses and documents`; `office create needs the rules confirmation`; `clears upazila and union when district changes`; `edit form shows division and district for a saved upazila`; `approver records documents received on an application`.
- [ ] **Steps 2–4.** **Step 5: Commit** — "Admin: paper-form fields with searchable address dropdowns".

### Task 9: Portal self-registration wizard + API registration fields

**Files:**
- Modify: `app/Filament/Member/Pages/Registration.php` — contact step uses `PersonFields` + `AddressFields` (present/permanent); nominee repeater uses them per row; new last step "Rules" showing current terms and a required `terms_accepted` checkbox; `submitAction` builds `TermsConsent(version shown on the page, Portal, uuid)`.
- Modify: `app/Http/Requests/Api/SaveRegistrationRequest.php` (or the existing request class used by `RegistrationController::update`) — validate new keys (`gender` in enum, `passport_no`, `*_upazila_id` exists, `*_union_id` exists, `*_address_line` max 255, same keys inside `nominees.*`).
- Modify: `SubmitRegistrationRequest` — required `terms_version` int.
- Modify: `app/Http/Controllers/Api/Member/RegistrationController.php` — `show` returns new fields (including derived `division_id`/`district_id` and both place names); `submit` builds `TermsConsent(terms_version, App, idempotency key, ip)`.
- Test: extend `tests/Feature/Api/ApiRegistrationTest.php`, `tests/Feature/Portal/*Registration*` (or `tests/Feature/Registration/RegistrationSchemaTest.php`)

- [ ] **Step 1: Failing tests** — `api saves and returns paper-form fields`; `api submit needs the rules version`; `portal wizard submits with the rules ticked`; `portal wizard refuses submit without the tick`.
- [ ] **Steps 2–4.** **Step 5: Commit** — "Registration: paper-form fields and rules agreement in portal and app API".

### Task 10: Rules admin page

**Files:**
- Create: `app/Filament/Clusters/Settings/Resources/MembershipTerms/…` (List + View + Edit-draft pages; table: version, status, published_at, published_by, acceptances count). Header actions: "New draft from current" (T1 → `SaveTermsDraft` prefilled), "Publish" (T3, typed confirmation, `PublishTerms`; modal warns every member must accept again).
- Test: `tests/Feature/Terms/MembershipTermsResourceTest.php`

- [ ] **Step 1: Failing tests** — `president drafts and publishes a new version`; `cashier cannot see publish`; `published version is read-only in the UI`.
- [ ] **Steps 2–4.** **Step 5: Commit** — "Settings: manage and publish the somiti rules".

### Task 11: Wrap-up

- [ ] `composer test`, `composer analyse`, `composer format:check` — all pass.
- [ ] Manual browser walk: admin create member (Bangla + English search in each dropdown), portal registration end-to-end, existing member login → accept rules page.
- [ ] Update `docs/` member manuals that describe the registration form (grep `docs` for "নিবন্ধন") with the new fields and the rules step.
- [ ] Commit — "Docs: registration manual covers paper-form fields and rules".

---

## Mobile app (Tasks 12–15)

**Repo:** `/Users/fazlerabbi/Desktop/projects/TONMY SOFT/somobay_somiti_mobile_app` (Flutter, GetX, Dio, `json_serializable`; separate git repo). Start a new branch `feature/paper-form` from its current branch `self-registration`. Starts after backend Tasks 2, 7 and 9 (they fix the API contract).

**Mobile constraints:** follow the module layout `lib/modules/<module>/{bindings,controller,model,repository,view}`; repositories return records `({Failure? failure, T? x})` via `ErrorHandler.handleException`; every string is a key in `lib/app/localization/translation_keys.dart` with text in **both** `bn_bd.dart` and `en_us.dart`; models are `@JsonSerializable` (regenerate with `dart run build_runner build --delete-conflicting-outputs`); tests use `test/support/fixtures.dart` JSON fixtures and `FakeApi`; the app never does money arithmetic (no change here). Verify each task with `flutter analyze` and `flutter test`.

### Task 12: Geo repository and searchable picker

**Files:**
- Create: `lib/modules/registration/model/geo_place_model.dart` (`GeoPlaceModel {int id; String nameBn; String nameEn; String label(String languageCode)}`)
- Create: `lib/modules/registration/repository/geo_repository.dart` — `IGeoRepository` with `divisions()`, `districts(int divisionId)`, `upazilas(int districtId)`, `unions(int upazilaId)`, each `Future<({Failure? failure, List<GeoPlaceModel> places})>`; in-memory cache per parent id (lists are small, so the whole child list is fetched once and searched locally).
- Modify: `lib/core/constants/api_constants.dart` (`geoDivisions`, `geoDistricts`, `geoUpazilas`, `geoUnions`)
- Create: `lib/core/widgets/app_searchable_picker.dart` — `AppSearchablePicker<T>` form field: tapping opens a bottom sheet with a search box and the list; `items`, `itemLabel`, `searchTerms(T) → List<String>` (both names), `onChanged`, `validator`, `enabled` (disabled until the parent is chosen), loading/error-with-retry states. Matching is case-insensitive "contains" on any search term; Bangla digits converted with `BanglaNumberUtil.toEnglish` where relevant.
- Test: `test/modules/geo_repository_test.dart`, `test/widgets/app_searchable_picker_test.dart`; fixtures `geo_divisions.json`, `geo_districts_6.json`, `geo_upazilas_47.json`, `geo_unions_495.json`

- [ ] **Step 1: Failing tests** — `parses places and caches by parent` (second call does not hit `FakeApi`); `picker filters by bangla and english` (type "গুলশান" and "gulsh" → Gulshan-1, Gulshan-2 shown; "xyz" → empty state); `picker is disabled without a parent`.
- [ ] **Steps 2–4:** `flutter test test/modules/geo_repository_test.dart test/widgets/app_searchable_picker_test.dart` FAIL → implement → PASS; `flutter analyze` clean.
- [ ] **Step 5: Commit** — "Geo: searchable division-to-union picker".

### Task 13: Address and person fields in the registration wizard

**Files:**
- Create: `lib/modules/registration/controller/address_form_state.dart` — `AddressFormState` (Rxn ids `divisionId`, `districtId`, `upazilaId`, `unionId`; `lineController`; `unionsAvailable` RxBool); `selectDivision/District/Upazila/Union` clear all descendants; `Map<String, dynamic> toJson(String prefix)` → `{prefix}_upazila_id`, `{prefix}_union_id`, `{prefix}_address_line`; `fromModel(RegistrationAddressModel?)` fills the chain from the API's derived `division_id` / `district_id`; `copyFrom(AddressFormState other)`.
- Create: `lib/modules/registration/view/widgets/address_fields.dart` — four `AppSearchablePicker`s + line field; union required only when `unionsAvailable`.
- Modify: `registration_model.dart` — `RegistrationDataModel` + `RegistrationNomineeModel` gain `gender`, `passport_no`, `present_address`, `permanent_address` (new `RegistrationAddressModel {upazilaId, unionId, divisionId, districtId, line, placeName}` matching backend Task 9's `show`); regenerate `.g.dart`.
- Modify: `nominee_form_row.dart` (gender, passport, two `AddressFormState`s, same-as-present flag), `nominee_card.dart`
- Modify: `registration_form_controller.dart` — step 1 (contact) sends gender, passport, both addresses (+ same-as-present); `errorKeysShownOn` includes the new keys and `nominees.$i.<new fields>`; `nomineeFields` extended; old `address` field removed from the form.
- Modify: `registration_form_page.dart` `_contact()`; `_review()` shows the new values (place names).
- Modify: translations (gender labels, address labels, "same as present", passport, picker search hint/empty/retry).
- Test: extend `test/modules/registration_form_controller_test.dart`, `test/modules/registration_form_page_test.dart`; update fixtures `registration_invited.json`, `registration_submitted.json`

- [ ] **Step 1: Failing tests** — `contact step sends gender, passport and both addresses`; `same as present copies the present address`; `changing district clears upazila and union`; `nominee row sends its addresses`; `server errors for nominees.0.present_upazila_id show on the nominee card`; `loading a saved draft fills the whole address chain`.
- [ ] **Steps 2–4.** **Step 5: Commit** — "Registration: paper-form fields with address pickers".

### Task 14: Rules agreement in registration and the re-acceptance gate

**Files:**
- Create: `lib/modules/terms/{model/terms_model.dart, repository/terms_repository.dart, controller/accept_rules_controller.dart, view/accept_rules_page.dart, view/rules_page.dart, bindings/terms_binding.dart}` — `TermsModel {version, rulesBn, rulesEn, declarationBn, declarationEn, accepted}`; `ITermsRepository.current()` (`GET terms/current`), `accept(int version, String idempotencyKey)` (`POST terms/accept`).
- Modify: `api_constants.dart` (`termsCurrent`, `termsAccept`), `app_routes.dart` / `app_pages.dart` (`/accept-rules`, `/rules`)
- Modify: registration review step: shows the rules + declaration (collapsible) and a required "আমি পড়েছি ও সম্মত" checkbox; submit disabled until ticked; `IRegistrationRepository.submit(String idempotencyKey, int termsVersion)` sends `terms_version`. A 422/409 `terms.errors.outdated` reloads the rules and asks again.
- Modify: `splash_controller.dart` — when `me` says `terms_acceptance_required`, route to `/accept-rules` instead of the member home (`me` model gains the two fields).
- Create: `lib/core/network/interceptors/terms_interceptor.dart` — on 403 with `meta.code == 'terms_acceptance_required'` navigate once to `/accept-rules` (`Get.offAllNamed`), then pass the error on; register it in `api_client.dart` after `AuthInterceptor`.
- Modify: `profile_page.dart` — "Rules I accepted" row → `/rules` (read-only, shows version and text in the current language).
- Accept page: rules text, checkbox, Accept button (one idempotency key per page), success → member home; logout available.
- Test: `test/modules/terms_repository_test.dart`, `test/modules/accept_rules_controller_test.dart`, extend `splash_controller_test.dart`, `registration_form_controller_test.dart`; fixtures `terms_current.json`, `error_403_terms_required.json`, `me_terms_required.json`

- [ ] **Step 1: Failing tests** — `submit sends the rules version`; `submit is blocked until the rules are ticked`; `outdated version reloads the rules`; `splash opens accept-rules when me requires it`; `a 403 terms_acceptance_required opens accept-rules once`; `accepting returns to the member home`; `retrying accept reuses the key`.
- [ ] **Steps 2–4.** **Step 5: Commit** — "Rules: agree at registration and accept new versions".

### Task 15: Mobile docs and release check

- [ ] Update `docs/MEMBER_API_SPECIFICATION.md` and `docs/MEMBER_API_MODELS.md` (geo endpoints, new registration fields, `terms/*`, `me` fields, the 403 code) and `docs/MEMBER_FEATURES.md`.
- [ ] `flutter analyze` and `flutter test` all pass; run on a phone/emulator against the local backend: register with Bangla search, existing member login → accept rules → home.
- [ ] Bump `pubspec.yaml` version (`1.0.2+4` → `1.1.0+5`) — the old app cannot submit registrations once the backend requires `terms_version`, so ship backend and app together.
- [ ] Commit — "Docs: paper-form registration and rules in the app".
