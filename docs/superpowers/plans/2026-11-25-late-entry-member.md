# Late Data Entry of a New Member — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new member's shares may start in a month that already has dues; the app then creates that member's normal dues for those already-generated months.

**Architecture:** `ShareChanger` gets a joining-only method with its own month guard (go-live, closed fiscal year, year-end, rate plan) in place of BR-1. A new `LateEntryDues` service builds the member's rows with the existing `MonthlyDueBuilder` for each generated month from the share start, with `due_date` = entry date, and `CreateMember` calls it. The New Member form and the registration approval show a server-computed summary line.

**Tech Stack:** Laravel / PHP 8.5, Filament, PostgreSQL, Pest.

**Spec:** `docs/superpowers/specs/2026-11-25-late-entry-member-design.md`

## Global Constraints

- Money is `Money` (integer poisha); no float, no `/`, no rounding; JS computes no money.
- Every user-facing string goes through `__()` and exists in both `lang/en` and `lang/bn`.
- Errors are `DomainRuleViolation::because('<key>', [...])`.
- `ChangeShares` (existing members), `decrease()` and exits keep BR-1 (`members.errors.month_generated`) unchanged.
- Late-entry dues are `origin = Regular`, no entry premium.
- After each task: `vendor/bin/pint --dirty --format agent`, then `composer analyse` must pass.

## Review Focus

- Share start == the latest generated month (single catch-up month): exactly one month of dues is created. Pinned in Task 2.
- Share start after the latest generated month (the normal case): no catch-up dues and behaviour identical to today. Pinned in Task 2.
- No dues ever generated (`latest()` null): join works as before. Covered by existing `onboard()` tests.
- A later re-run of `GenerateMonthlyDues` for the catch-up months creates no duplicates. Pinned in Task 2.
- A late entry that is also a late joiner (`dues_start_month` < share start): back-dues before `S`, regular dues from `S`. Pinned in Task 2.

---

### Task 1: Joining-month guard in `ShareChanger`

**Files:**
- Modify: `app/Domain/Members/Services/ShareChanger.php`
- Modify: `app/Domain/Members/Actions/CreateMember.php` (call `join()` instead of `increase()`)
- Modify: `lang/en/members.php`, `lang/bn/members.php` (`errors` array)
- Test: `tests/Feature/Members/LateEntryTest.php` (create with `php artisan make:test --pest LateEntryTest`, then move into `tests/Feature/Members/`)

**Interfaces:**
- Produces: `ShareChanger::join(User $actor, Member $member, int $shares, YearMonth $from): ShareLot`, which is `increase()` with `assertJoinMonthAllowed()` instead of the BR-1 check (same fee charge and `ShareTransaction` with reason `'Joined'`).
- Produces: `ShareChanger::assertJoinMonthAllowed(YearMonth $from): void` (public, also used by the previews in Task 3). Checks, in order:
  1. go-live month (`GoLiveMonth::get()`) not null and `$from` before it → `members.errors.join_before_go_live` `['month' => go-live]`
  2. `FiscalYear` with `start_year = FiscalCalendar::startYearFor($from)` has status `Closed` → `members.errors.join_year_closed` `['year' => code]`
  3. a `YearEnd` (any status) exists for that fiscal year → `members.errors.join_year_end` `['year' => code]`
  4. `planFor($from)` (existing `no_rate_plan` error)

- [ ] **Step 1: Create branch and commit the spec and plan**

```bash
git checkout -b fix/late-entry-member
git add docs/superpowers/specs/2026-11-25-late-entry-member-design.md docs/superpowers/plans/2026-11-25-late-entry-member.md
git commit -m "Design and plan: late data entry of a new member"
```

- [ ] **Step 2: Write the failing tests**

Setup mirrors `tests/Feature/Members/BackDuesTest.php` `beforeEach`: travel to 2026-07-01, seed `ChartOfAccountsSeeder`, open FY 2026, `approvedPlan('2026-07', '1000', [... service 20, registration 200, fixed late fee 50 once])`, `onboard(1, '2026-07')`, `generateMonth()` for 2026-07..2026-12, then `travelTo('2026-11-25')`.

```php
it('lets a new member join in a month that already has dues', function (): void {
    $member = onboard(2, '2026-10', ['mobile' => '01799999999']);
    expect($member->shareLots()->sole()->effective_from->equals(YearMonth::parse('2026-10')))->toBeTrue();
});

it('still refuses a share change for an existing member in a generated month', function (): void {
    $key = ruleKeyOf(fn () => app(ChangeShares::class)(userWithRole(Role::Secretary), Member::query()->first(), 1, YearMonth::parse('2026-10')));
    expect($key)->toBe('members.errors.month_generated');
});

it('refuses a join month before go-live')          // set go-live 2026-08 the way GoLive tests do; join '2026-07' → 'members.errors.join_before_go_live'
it('refuses a join month in a closed fiscal year')  // mark FY 2026 Closed directly; → 'members.errors.join_year_closed'
it('refuses a join month whose year-end is prepared') // YearEnd row for FY 2026 (factory or PrepareYearEnd); → 'members.errors.join_year_end'
```

Use the rule-key helper already used in `GenerateMonthlyDuesTest.php` (`generationKey`) or the project's equivalent; check `tests/Pest.php` for a shared one before adding one.

- [ ] **Step 3: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/Members/LateEntryTest.php`
Expected: the happy-path and three new-rule tests FAIL (`month_generated` thrown); the `ChangeShares` test passes.

- [ ] **Step 4: Implement `join()` and `assertJoinMonthAllowed()`; switch `CreateMember` to `join()`; add the three error strings in en and bn**

en copy:
- `join_before_go_live` => `'Shares cannot start before the society\'s first month in the app (:month).'`
- `join_year_closed` => `'Fiscal year :year is closed, so a new member\'s shares cannot start in it.'`
- `join_year_end` => `'The year-end for :year has already been prepared, so a new member\'s shares cannot start in it.'`

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Feature/Members/LateEntryTest.php tests/Feature/Members tests/Feature/Contributions/GenerateMonthlyDuesTest.php`
Expected: PASS

- [ ] **Step 6: Pint, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent && composer analyse
git add -A app lang tests && git commit -m "Let a new member's shares start in a month that already has dues"
```

---

### Task 2: Create the catch-up dues (`LateEntryDues`)

**Files:**
- Create: `app/Domain/Contributions/LateEntry/LateEntryDues.php` (next to `BackDues/`)
- Modify: `app/Domain/Members/Actions/CreateMember.php`
- Test: `tests/Feature/Members/LateEntryTest.php`

**Interfaces:**
- Consumes: `ShareChanger::join()` (Task 1); `MonthlyDueBuilder::rows(YearMonth, RatePlan, array<int,int>): list<array>`; `GeneratedMonths::latest(): ?YearMonth`; `RateResolver::for(YearMonth): RatePlan`.
- Produces:
  - `LateEntryDues::months(YearMonth $shareStart): list<YearMonth>`: `$shareStart` up to and including `latest()`; empty when `latest()` is null or before `$shareStart`.
  - `LateEntryDues::total(int $shares, YearMonth $shareStart): Money`: sum of deposit + service charge per month at each month's plan (for previews).
  - `LateEntryDues::write(User $actor, Member $member, YearMonth $shareStart, CarbonImmutable $enteredOn): int`: for each month, `MonthlyDueBuilder::rows($month, plan, [$member->id])`, each row with `due_date` overridden to `$enteredOn->toDateString()`, inserted with `DB::table('dues')->insertOrIgnore()`. Returns how many rows were inserted. When > 0, logs `activity('members')->causedBy($actor)->performedOn($member)->withProperties(['months' => [...], 'amount_poisha' => total])->log('late_entry_dues_created')`.
- `CreateMember` calls `write()` right after `join()` and `BackDuesWriter::write()`, with the same `CarbonImmutable::now(YearMonth::TIMEZONE)`.

- [ ] **Step 1: Write the failing tests** (same file, same setup)

```php
it('creates regular dues for every already-billed month from the share start', function (): void {
    $member = onboard(2, '2026-10', ['mobile' => '01799999999']);
    $dues = Due::query()->where('member_id', $member->id)->whereIn('type', [DueType::Deposit, DueType::ServiceCharge])->orderBy('month')->get();

    expect($dues->map(fn (Due $d): string => (string) $d->month)->unique()->values()->all())->toBe(['2026-10', '2026-11', '2026-12'])
        ->and($dues->every(fn (Due $d): bool => $d->origin === DueOrigin::Regular && $d->due_date->toDateString() === '2026-11-25'))->toBeTrue()
        ->and($dues->where('type', DueType::Deposit)->first()->amount_poisha->equals(Money::ofTaka('2000')))->toBeTrue()
        ->and(Due::query()->where('member_id', $member->id)->where('type', DueType::EntryPremium)->exists())->toBeFalse();
});

it('creates one month when the share start is the latest generated month')   // join '2026-12' → months ['2026-12']
it('creates no catch-up dues when the share start is after the latest generated month') // join '2027-01' → no Deposit dues yet
it('adds nothing when the monthly run is repeated for the catch-up months')   // count dues, generateMonth 2026-10..12 again (travel back to 2026-11-25 after), count unchanged
it('charges no late fee for the catch-up months on entry day')               // app(ApplyLateFees::class) as in BackDuesTest; no LateFee due for member
it('bills back-dues before the share start and regular dues from it')         // CreateMember with $duesStartMonth '2026-08', share start '2026-10': Aug–Sep origin BackDues, Oct–Dec origin Regular
it('logs the late-entry dues on the member')                                  // Activity description 'late_entry_dues_created', properties months ['2026-10','2026-11','2026-12']
```

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/Members/LateEntryTest.php`
Expected: new tests FAIL (no Oct–Dec dues for the member).

- [ ] **Step 3: Implement `LateEntryDues` and wire it into `CreateMember`**

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/Members tests/Feature/Contributions tests/Feature/Registration`
Expected: PASS

- [ ] **Step 5: Pint, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent && composer analyse
git add -A app tests && git commit -m "Bill a late-entered member's already-generated months as regular dues"
```

---

### Task 3: Show it on the New Member form and the registration approval

**Files:**
- Create: `app/Filament/Resources/Members/Support/LateEntryPreview.php`
- Modify: `app/Filament/Resources/Members/Schemas/MemberForm.php` (shares section)
- Modify: `app/Filament/Resources/MemberApplications/Actions/RegistrationActions.php:245-270` (`feeSummary`)
- Modify: `lang/en/members.php`, `lang/bn/members.php`
- Modify: `tests/Feature/Registration/RegistrationAdminTest.php:118`
- Test: `tests/Feature/Members/LateEntryTest.php`

**Interfaces:**
- Consumes: `ShareChanger::assertJoinMonthAllowed()` (Task 1), `LateEntryDues::months()` / `total()` (Task 2).
- Produces: `LateEntryPreview::summary(mixed $shares, mixed $from): string`. Empty string for invalid input or no catch-up months; the violation's message when `assertJoinMonthAllowed()` throws; otherwise `__('members.late_entry.summary', ['months' => 'Oct 2026, Nov 2026, Dec 2026', 'total' => Display::money($total)])`. Month names come from the existing month display helper in `App\Filament\Support\Display` (check for one; otherwise `YearMonth` formatting used elsewhere in Members).

- [ ] **Step 1: Write the failing tests**

```php
it('creates a late-entry member from the New Member page', function (): void {
    // Livewire::test(CreateMember page) as MemberResourceTest does; fill shares 2, effective_from '2026-10'
    // ->assertSee(__('members.late_entry.summary', [...]))   // Oct, Nov, Dec; total ৳ 6,120.00 for 2 shares (1000+20 ×2 ×3)
    // ->call('create') ->assertHasNoFormErrors(); member exists with lot from 2026-10
});
```

In `RegistrationAdminTest.php:118`, the approval at `2026-07` (already generated) is now allowed. Change that assertion to `->assertMountedActionModalSee(` the late-entry summary for 2026-07 `)`, and keep the rest of the test approving at `2026-08`.

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/pest tests/Feature/Members/LateEntryTest.php tests/Feature/Registration/RegistrationAdminTest.php`
Expected: FAIL (summary text not shown).

- [ ] **Step 3: Implement**

- `MemberForm`: a `TextEntry::make('late_entry_preview')` in the shares section (create only, always visible, `columnSpanFull`), state from `LateEntryPreview::summary($get('shares'), $get('effective_from'))`. Helper text on `effective_from` stays the same.
- `RegistrationActions::feeSummary()`: replace the `month_generated` branch with `assertJoinMonthAllowed($month)`, then return the fee line plus, when non-empty, the late-entry summary on the next sentence.
- en copy: `'late_entry' => ['summary' => 'Already billed for :months: these will be added as normal dues of :total.']`; bn equivalent.
- Reword so staff can tell late entry from a late joiner (en; bn equivalents):
  - `member.effective_from` => `'Joined the somiti in'`
  - `member.effective_from_help` => `'The month this member really joined. If you are entering them later, still pick their real joining month; months already billed are added as normal dues.'`
  - `member.dues_start_month_help` => `'Only for a new person joining late who must also pay for months before they joined, plus the entry premium. Otherwise keep it the same as the joining month.'`
  - Update any test that asserts the old label/help text.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/Members tests/Feature/Registration`
Expected: PASS

- [ ] **Step 5: Pint, analyse, format check, commit**

```bash
vendor/bin/pint --dirty --format agent && composer analyse && composer format:check
git add -A app lang tests && git commit -m "Show already-billed months on the New Member form and registration approval"
```

- [ ] **Step 6: Ask the user to run the full suite** (`php artisan test --compact`)
