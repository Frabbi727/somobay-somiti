# Registration matches the paper form (part 1 of 2)

## Context
The somiti uses a paper "সদস্য নিবন্ধন ফর্ম / চুক্তিপত্র" (docs/manual-page/রয়েল ডিস্ট্রিক্ট মৈত্রী সমিতি …pdf) with 24 rules (নীতিমালা ও নিয়মাবলি). The app's registration captures less than the paper and never records that a member agreed to the rules. The user chose: **make the app match the paper.** This plan covers part 1 (registration data + agreement). Part 2 (enforcing rules: 2-year lock-in, 3–6 month payout deadline, 6-month-arrears alert, family continuing after death, per-share late fee, ad-hoc fines) is a separate brainstorm afterwards, because rules 2 and 15/17 need the user's interpretation first.

## Decided by the user
- Agreement = **in-app tick only**; the app stores which rules version was accepted and when.
- **Existing members must accept the current rules on next login** (portal and app).
- Address = searchable chained dropdowns **বিভাগ → জেলা → উপজেলা → ইউনিয়ন** from `database/demrcation/*.csv` (source: github.com/Frabbi727/bangladesh-complete-geo-data). **ইউনিয়ন replaces থানা.** Plus a free-text house/road/village line.
- Documents checklist = **tick-list only** (no file uploads).
- **Required at submit:** gender, present address, permanent address (or "same as present"), every nominee's addresses.
- **Existing members:** new fields stay blank, old free-text `address` remains visible; filled in later.

## Design

### 1. Geo data
- Migration creates `geo_divisions`, `geo_districts`, `geo_upazilas`, `geo_unions` (id from CSV, parent FK, `name_en`, `name_bn`; lat/long/website dropped). Indexes on parent FK and names.
- The same migration loads the CSVs (no header row; columns: divisions `id,name_en,name_bn,url`; districts `id,division_id,name_en,name_bn,lat,lon,url`; upazilas/unions `id,parent_id,name_en,name_bn,url`) so every environment, including production, has the data. Upsert by id → safe to rerun.
- Read-only `Geo` models in `app/Domain/Shared` (or a new `app/Domain/Geo` — check §4 project structure first), no write UI.
- A member address is stored as **`{prefix}_upazila_id` + `{prefix}_union_id` + `{prefix}_address_line`**. Five upazilas in the data have no unions (e.g. Guimara, Dasar), so union is required only when the chosen upazila has unions and must belong to it. Division/district are derived from the upazila.

### 2. New fields
| Table | New columns |
|---|---|
| `members`, `member_applications` | `gender`, `passport_no` (nullable), `present_union_id`, `present_address_line`, `permanent_union_id`, `permanent_address_line`, `documents_received` (jsonb list) |
| `nominees`, `member_application_nominees` | `gender`, `passport_no`, `present_union_id`, `present_address_line`, `permanent_union_id`, `permanent_address_line` |

- New `Gender` backed enum (HasLabel/HasColor/HasIcon): `male`, `female` (paper) + `other` — *my call* (third gender is legally recognised; drop it if you disagree).
- New `RegistrationDocument` enum: `nid`, `passport`, `nominee_nid`, `nominee_photo`. Only office staff tick it (secretary on member form / approvers on application view).
- All new columns nullable in the DB (existing rows); "required" is enforced at **submit** by the existing rules classes (`NomineeRules`, registration submit validation), not by NOT NULL.
- "Same as present" is a UI convenience that copies the values; both addresses are always stored.
- `DecideRegistration` final approval copies the new fields (and nominee fields) into `members`/`nominees` via `CreateMember`, as it does today.

### 3. Rules versions and agreement
- `membership_terms`: `version` (unique int), `rules_bn`, `rules_en`, `declaration_bn`, `declaration_en` (the চুক্তিপত্র paragraph), `status` draft|published, `published_at`, `published_by`. Published rows immutable (model guard + trigger, like rate plans).
- Seed **v1 = the 24 rules + declaration from the PDF** (Bangla verbatim; English translation drafted by me, flagged for the user to check).
- `terms_acceptances` (insert-only, append_only_guard trigger): `terms_id`, `member_id` nullable, `application_id` nullable (exactly one set), `user_id`, `channel` (portal|app|office), `accepted_at`, `ip`, `idempotency_key`. Unique per (subject, terms_id).
- Actions (`app/Domain/Members/Actions` or a `Terms` folder per conventions): `PublishTerms` (T3 confirm, new permission `terms.publish`, default president+secretary — *my call*), `AcceptTerms`.
- Flows:
  - **Self-registration (portal + API):** last step shows the rules + declaration with an "আমি সম্মত" tick; submit fails without it; `SubmitRegistration` writes the acceptance against the application; final approval links it to the new member.
  - **Office entry (admin Members form):** secretary ticks "the member has read and agreed to rules vN" → acceptance with channel `office`, `user_id` = secretary.
  - **Re-acceptance:** if a member's latest acceptance ≠ current published version, the portal redirects to an "Accept rules" page (middleware on the member panel), and the API returns a fixed error code `terms_acceptance_required` on member routes; new endpoints `GET /api/v1/terms/current`, `POST /api/v1/terms/accept`. Applies to existing members (no acceptance yet) and to everyone when a new version is published.
- Members can always view the rules they accepted (portal Profile + API).

### 4. UI / API
- Shared Filament schema component for an address block (four `Select`s, `searchable()`, `live()`, child options filtered by parent via `Get`, child cleared when parent changes; search matches `name_bn` and `name_en`) reused for member present/permanent and each nominee repeater item. Used in:
  - `app/Filament/Resources/Members/Schemas/MemberForm.php`
  - `app/Filament/Member/Pages/Registration.php`
  - `MemberInfolist.php` / `MemberApplicationInfolist.php` (show the full place names)
- Public, cached geo endpoints for the mobile app: `GET /api/v1/geo/divisions`, `/geo/districts?division_id=&q=`, `/geo/upazilas?district_id=&q=`, `/geo/unions?upazila_id=&q=` (bn+en names).
- `RegistrationController` (`app/Http/Controllers/Api/Member`) + `RegistrationDraft` DTO + `SaveRegistrationDraft` accept the new fields and `terms_accepted`.
- The Flutter app (`../somobay_somiti_mobile_app`) is in scope: searchable address pickers, the new fields, the rules tick on submit, and an accept-rules screen reached from start-up (`auth/me` reports `terms_acceptance_required`) or from a 403 with `meta.code = terms_acceptance_required`.
- All strings in `lang/bn` + `lang/en` (the bn/en parity test will catch misses).

## Critical files
- New migrations (geo + load, member/application/nominee columns, terms tables)
- `app/Domain/Members/Registration/Actions/{SaveRegistrationDraft,SubmitRegistration,DecideRegistration}.php`, `Data/RegistrationDraft.php`
- `app/Domain/Members/Actions/{CreateMember,UpdateMember}.php`, nominee rules service
- `app/Filament/Resources/Members/Schemas/MemberForm.php`, `app/Filament/Member/Pages/Registration.php`, infolists
- `app/Http/Controllers/Api/Member/RegistrationController.php`, `routes/api.php`
- `app/Domain/Settings/Services/RolePermissions.php` (new permission)

## Verification
- Pest: geo load (counts 8/64/upazilas/unions, idempotent rerun); chained-dropdown options filter correctly; submit rejected without gender/addresses/nominee addresses/terms tick; approval copies all fields + links acceptance; office create records `office` acceptance; existing member is redirected / API returns `terms_acceptance_required` until accepted; publishing v2 forces re-acceptance; published terms and acceptances immutable; arch rules still pass.
- `composer test`, `composer analyse`, `composer format:check`.
- Manual: walk the portal registration and admin member form in the browser; hit the geo API with search in Bangla and English.

## After approval
Commit this design as `docs/superpowers/specs/2026-11-01-registration-matches-paper-design.md`, then write the implementation plan (superpowers:writing-plans). Part 2 (rule enforcement) gets its own brainstorm.
