# Member self-registration and approval — design

Date: 2026-10-07 · Status: awaiting review

Repos: backend `somobay-somiti` (branch `production`), mobile `somobay_somiti_mobile_app` (branch `member-api`).

## 1. Purpose

**Problem today.** The secretary collects every new member's details and enters them in the admin
form (SOMITI_SPEC §1.6 W1, `App\Domain\Members\Actions\CreateMember`). The member becomes `active`
immediately; the share lot and registration-fee due are created and the welcome SMS is sent in the
same step. This costs office time, causes typing mistakes, the member cannot check their own data,
and nobody can see where a registration stands.

**New process.** The office creates only a login (mobile + password). The member signs in on the
app or the web portal, fills in their own registration, reviews it and submits it. The submission
passes through a configurable approval chain (default Secretary → President). Only the final
approval creates the real member — with the existing `CreateMember` logic, so member number,
shares, registration fee and welcome SMS are produced exactly as today.

**Success.**
- The office enters two fields to start a registration.
- At any time the member can see: what they completed, what is pending, who acts next, whether it
  was returned or rejected and why, and what they must do.
- No approval role is hard-coded in the app or portal; the backend sends the steps and labels.
- Existing members, their logins and all accounting are unchanged.
- No member row, share lot, due or journal exists for an applicant before final approval.

## 2. Decisions (agreed 2026-10-07)

| Topic | Decision |
|-------|----------|
| Where a registration lives | A separate `member_applications` record (approach A). `members` gets **no** new statuses. Rejected alternative: pending statuses on `members` — at least 7 queries treat "not exited" as eligible (payments, late fees, reports, …) and would leak applicants into financial processing. |
| Approval chain | Ordered list of roles, sequential. Default `[secretary, president]`. Stored in the database (`somiti_profiles.registration_approval_roles`), editable by staff with settings rights. |
| Shares | Member requests a share count. The final approver confirms or changes it and picks the effective month. Share lot and registration-fee due are created only at final approval. |
| Rejection | Approver chooses **Return for correction** (reason required; member edits and resubmits; approvals restart at step 1) or **Reject permanently** (reason required; application closed; login blocked). |
| Fields | Same as the office form: `name_bn`, `name_en` required; guardian name, NID, date of birth, email, address, photo optional. **New:** at least one nominee; nominee NID required; nominee shares total 100%; relation from a list. No new member fields. |
| Nominee relation | New table `nominee_relations` (bn/en label, sort, active), managed in admin, served by the API. Adding a relation needs no deploy or app release. |
| Office form | The same nominee rules apply to the admin create/edit member form. |
| Existing create form | Kept, for members who cannot use a phone. |
| Notifications | SMS when returned, rejected or approved (approval sends the existing welcome SMS). Filament database notification to the approvers whose step is due. |

## 3. What exists today (inspection summary)

- `members.status` CHECK allows `active | inactive | exited` only. `CreateMember` sets `active`.
- Sign-in (API `MemberCredentials`, portal `MemberLogin`) finds the **member** by `members.mobile`;
  any status except `exited` may sign in. Without a member row there is no login.
- `PortalAccounts::forMember` lazily creates the member's `User` (role `member`) and links
  `members.user_id`. Staff set member passwords with `SetPortalPassword`; no self-service reset.
- API tokens (`MemberTokens`): access token ability `member` (60 min), refresh ability `refresh`
  (30 days), one family per sign-in. Member routes use `auth:sanctum, abilities:member, member`.
- No generic approval table. Precedent: rate plans (`rate_plan_approvals`, `REQUIRED_ROLES =
  [President, Secretary]`, `submission_no`, `ApprovalDecision approve|reject`).
- Nominees: `name`, `relation` (free text), `mobile`, `nid` (optional), `share_bps`.
- Reusable: `ApiResponse` envelope, `ApiValue` (enum/money/date), body `idempotency_key`,
  `ConfirmsWithTier`, `DomainActionRunner`, `SmsSender` + `SmsTemplateKey`, spatie activity log.
- Mobile: every login goes straight to the dashboard; an old unrouted register screen calls
  non-existent endpoints and is left untouched.

## 4. State machine

`App\Domain\Members\Registration\Enums\MemberApplicationStatus` (HasLabel, HasColor, HasIcon):

```text
              InviteMember
                   │
                   ▼
              ┌─────────┐   SaveRegistrationDraft (any number of times)
              │ invited │◄──────────┐
              └────┬────┘           │
                   │ SubmitRegistration
                   ▼
             ┌───────────┐  approve (not last step) → current_step + 1, stays submitted
             │ submitted │──────────────────────────┐
             └─┬───┬───┬─┘◄─────────────────────────┘
     return    │   │   │ reject
   ┌───────────┘   │   └──────────────┐
   ▼               │ approve (last)   ▼
┌──────────┐       ▼            ┌──────────┐
│ returned │  ┌──────────┐      │ rejected │ (final; login blocked)
└────┬─────┘  │ approved │      └──────────┘
     │        └──────────┘ (final; member created, member_id set)
     │ SaveRegistrationDraft / SubmitRegistration
     └──────────► submitted (submission_no + 1, current_step = 0)
```

Open statuses (block a second application with the same mobile): `invited`, `submitted`,
`returned`. The member-facing "where am I" is derived, not stored: the step at `current_step`
of the current submission is "pending", earlier steps "done", later ones "waiting".

`next_action` sent to clients:

| Status | `next_action` | Meaning |
|--------|---------------|---------|
| invited | `complete` | Fill in and submit the registration |
| returned | `resubmit` | Fix the details and submit again |
| submitted | `wait` | Waiting for the approver of the current step |
| rejected | `none` | Closed; contact the office |
| approved | `none` | Sign in again as a member (clients move to the dashboard) |

## 5. Data model

All money stays out of this feature; shares are counts and nominee shares are basis points.

### 5.1 `nominee_relations` (new)
`id`, `key string(30) unique`, `label_bn`, `label_en`, `sort smallint default 0`,
`active bool default true`, timestamps. Seed: father, mother, spouse, son, daughter, brother,
sister, other (labels: see §12 UNKNOWN). Never hard-deleted (deactivate instead) because nominees
reference it.

### 5.2 `nominees` (changed)
Add `relation_id` nullable FK → `nominee_relations`. The existing `relation` text column stays for
legacy rows and is shown when `relation_id` is null. New and edited nominees always get
`relation_id`; `relation` is filled with the English label for readability of old reports.

### 5.3 `member_applications` (new)
| Column | Notes |
|--------|-------|
| id | |
| user_id | FK users, unique, NOT NULL — the applicant login |
| mobile | `string(11)`, CHECK `^01[3-9][0-9]{8}$`; partial unique index `WHERE status IN ('invited','submitted','returned')` |
| status | `string(10)`, CHECK in the 5 statuses |
| name_bn, name_en, guardian_name, nid, date_of_birth, email, address, photo_path | draft values, all nullable (drafts may be partial); same formats as `members` |
| requested_shares | int nullable, CHECK > 0 |
| approval_chain | jsonb list of role values, snapshot taken at submit |
| current_step | smallint nullable |
| submission_no | int default 0 |
| submit_idempotency_key | uuid nullable, unique |
| invited_by | FK users NOT NULL |
| member_id | FK members nullable, unique; set on approval |
| submitted_at, decided_at | timestampTz nullable |
| timestamps | no soft deletes; never deleted |

### 5.4 `member_application_nominees` (new)
`application_id`, `name`, `relation_id` FK, `mobile` nullable, `nid` NOT NULL (10/13/17 digits
CHECK), `share_bps` (1–10000 CHECK), `sort`. Replaced as a whole on each draft save (only while
the application is editable).

### 5.5 `member_application_decisions` (new, insert-only)
`application_id`, `submission_no`, `step`, `role string(20)`, `user_id`, `decision`
(CHECK `approve | return | reject`), `reason` text nullable (required for return/reject, CHECK),
`created_at`. UNIQUE (`application_id`, `submission_no`, `step`). Model guard + PostgreSQL trigger
block UPDATE/DELETE (same as `rate_plan_approvals` / activity log).

### 5.6 `somiti_profiles.registration_approval_roles` (new column)
jsonb, NOT NULL, default `["secretary","president"]`. Values must be staff `Role` values except
`super_admin` and `auditor`; at least one; no duplicates. Edited on the Somiti profile settings
page (T2).

## 6. Business rules

- **R1 Invite.** Requires permission `members.create`. Mobile must be valid and not used by any
  member (including exited and soft-deleted, as `MemberRules` does today) or any open application.
  Password min 6 (as `SetPortalPassword`). Creates the `User` (role `member`, name = mobile until
  approval, locale bn) and the application `invited`. Activity log `members/registration.invited`.
- **R2 Edit.** Only the applicant, only while `invited` or `returned`. Partial saves allowed; each
  field is format-checked on save (mobile is not editable — it is the login).
- **R3 Submit.** Full validation: `MemberRules` (names, NID format/uniqueness against members and
  open applications) + `NomineeRules` (≥ 1 nominee; each has name, relation from the active list,
  NID; optional mobile valid; shares total exactly 10000 bps) + `requested_shares ≥ 1`. Snapshot
  `approval_chain` from the profile, `submission_no + 1`, `current_step = 0`, `submitted_at = now`.
  Same `idempotency_key` → returns the current state without changes; a key used with another
  application → 409.
- **R4 Decide.** Only on `submitted`. The actor must hold the role at `current_step`, must not be
  the applicant, and must not have decided an earlier step of the same submission (one person, one
  step). Approve on a non-final step → `current_step + 1`, notify the next role. Return/Reject
  need a reason (≥ 5 chars, as `RejectRatePlan`).
- **R5 Final approval.** The approver gives `shares` (default `requested_shares`) and
  `effective_from` (YearMonth). In one transaction: call `CreateMember` with the application data
  and the applicant's existing `User` (refactor: `PortalAccounts::forMember` links a given user
  instead of creating one); set user name to `name_en`; set `member_id`, status `approved`,
  `decided_at`. `CreateMember` rules apply unchanged — e.g. no approved rate plan for the month →
  `DomainRuleViolation`, nothing is saved. Welcome SMS is sent by the existing `MemberJoined`
  listener. `joined_on` = approval date.
- **R6 Return.** Status `returned`, SMS `registration_returned` with the reason. Draft data is kept.
- **R7 Reject.** Status `rejected`, SMS `registration_rejected`, all the applicant's tokens revoked;
  sign-in refuses this login from then on. The mobile number becomes free for a new invitation.
- **R8 Approvers cannot edit applicant data.** They return it with a reason. Only shares and the
  effective month are set at the final step.
- **R9 Chain changes** affect only submissions made after the change (snapshot).
- **R10** Every Action: final invokable class, `DB::transaction(attempts: 3)`, `lockForUpdate()`
  on the application, activity log, `DomainRuleViolation` with bn/en keys. Lock order for final
  approval: application → then the existing `CreateMember` order (member → dues → …).

## 7. Authentication changes (extend, no second login system)

- **Credentials.** `MemberCredentials::byPassword` / `byCode` and `MemberLogin`: look up the member
  by mobile first (unchanged). If none, look up an application with that mobile in `invited`,
  `submitted` or `returned` and check its user's password. Rejected/approved applications are not
  used for sign-in (an approved one is reached through its member).
- **Account type.** A small `AccountType` resolver: `member` when the user has a non-exited member,
  `applicant` when the user has an open application, otherwise none (sign-in fails).
- **Tokens.** `MemberTokens::issue` gives the access token ability `member` or `applicant` from the
  account type at issue time. Refresh re-evaluates it, so after final approval the next refresh
  yields a member token without signing in again.
- **On final approval** only the applicant's *access* tokens are revoked; the refresh token
  stays, so the app's next call gets 401 → refresh → member token. **On reject** all tokens are
  revoked.
- **Routes.** `auth/logout` and `auth/me` move to a group that accepts either ability
  (`auth:sanctum`, `ability:member,applicant`). The member group gains a middleware that answers an
  applicant token with **403 `api.registration.member_only`** ("Your registration is not approved
  yet. Please update the app if you don't see your registration status.") — so an old app build
  shows a message instead of looping on 401/refresh.
- **Portal.** `User::canAccessPanel('member')` also allows applicants. A member-panel middleware
  redirects applicants to the two registration pages; member pages keep `ScopedToMember`.

## 8. API specification

Base `/api/v1`, envelope `{success, statusCode, message, data, errors, meta}`, `Accept-Language`
bn|en, enums `{value, label, color}`, dates `YYYY-MM-DD`, timestamps ISO-8601 Asia/Dhaka.

### 8.1 `POST auth/login` (changed)
Request unchanged. Response `data` adds:
```json
{ "access_token": "…", "refresh_token": "…", "token_type": "Bearer", "expires_in": 3600,
  "account_type": "member" }
```
For an applicant `account_type` is `"applicant"`. Errors unchanged (422 on `mobile`, 429).

### 8.2 `GET auth/me` (changed) — ability `member` or `applicant`
Member: unchanged fields + `"account_type": "member"`.
Applicant:
```json
{ "account_type": "applicant", "mobile": "01712345678",
  "registration": { "status": {"value":"submitted","label":"…","color":"warning"},
                    "next_action": "wait" } }
```

### 8.3 `GET config/nominee-relations` — public
`data: [{ "id": 1, "key": "father", "label": "পিতা" }]`, active only, ordered by `sort`.

### 8.4 `GET registration` — ability `applicant`
```json
{
  "status": {"value":"submitted","label":"অনুমোদনের অপেক্ষায়","color":"warning"},
  "next_action": "wait",
  "can_edit": false,
  "headline": "সভাপতির অনুমোদনের অপেক্ষায়",
  "message": "সম্পাদক আপনার নিবন্ধন যাচাই করেছেন। এখন সভাপতির অনুমোদনের অপেক্ষায়।",
  "timeline": [
    {"key":"submitted","label":"তথ্য জমা","state":"done","acted_at":"2026-10-07T10:00:00+06:00","actor":null,"reason":null},
    {"key":"step_0","label":"সম্পাদকের অনুমোদন","state":"done","acted_at":"…","actor":"…","reason":null},
    {"key":"step_1","label":"সভাপতির অনুমোদন","state":"pending","acted_at":null,"actor":null,"reason":null},
    {"key":"activation","label":"সদস্যপদ চালু","state":"waiting","acted_at":null,"actor":null,"reason":null}
  ],
  "decision": null,
  "data": {
    "name_bn": "…", "name_en": "…", "guardian_name": null, "nid": null, "date_of_birth": null,
    "mobile": "01712345678", "email": null, "address": null, "photo_url": null,
    "requested_shares": 2,
    "nominees": [{"name":"…","relation_id":1,"relation":"পিতা","mobile":null,"nid":"…","share_percent":"100"}]
  }
}
```
- `state`: `done | pending | waiting | returned | rejected`. Labels are role labels
  (`Role::getLabel()`), localised — clients never translate roles.
- `decision` (when returned/rejected): `{type, label, by_role, at, reason}`.
- Before the first submit the timeline shows the chain configured *now*; after submit, the snapshot.
- `photo_url` is a short-lived signed URL (same approach as the logo).

### 8.5 `PUT registration` — ability `applicant`
Body: any subset of `name_bn, name_en, guardian_name, nid, date_of_birth, email, address,
requested_shares, nominees[]` (`nominees` replaces the whole list; each
`{name, relation_id, mobile?, nid, share_percent}`; `share_percent` a decimal string with ≤ 2
places, converted to bps on the server — the client does no arithmetic beyond showing a running
total). Response: same as `GET registration`. Errors: 422 field errors (formats), 422
`registration.errors.not_editable` when not `invited|returned`.

### 8.6 `POST registration/photo` — ability `applicant`
Multipart `photo` (jpg/png, ≤ 1 MB, as the admin form). Stored on the private local disk under
`member-photos`; on approval the path is copied to the member. Response: `GET registration` shape.

### 8.7 `POST registration/submit` — ability `applicant`
Body `{ "idempotency_key": "<uuid>" }`. Runs R3. Response 200 with the `GET registration` shape
(status `submitted`). Errors: 422 with field errors (`nominees`, `nominees.0.nid`, …) or a rule
message; 409 `registration.errors.idempotency_conflict`; 422 `not_editable`.
Side effects: decision-free; notifies approvers of step 0; activity log.

### 8.8 Member routes with an applicant token
403 `api.registration.member_only` (see §7).

## 9. Admin (Filament) flow

- **Invite member** — header action on the Members list. Form: mobile, password, confirm. T2
  summary. Calls `InviteMember`. Shows the mobile and password once so the office can tell the
  member.
- **Registrations** — new `MemberApplicationResource` under the Members navigation group.
  List: mobile, name, status chip, current step label, submitted date; filters by status and
  "waiting for me". View: the same timeline the member sees, all data, nominees, photo, decision
  history. Actions (only the role at the current step sees them):
  - **Approve** — T3 typed confirmation. On the final step the form also has `shares` (default
    requested) and `effective_from` (month), with the registration-fee preview from
    `RegistrationFees`.
  - **Return for correction** — T3 with required reason.
  - **Reject permanently** — T3 with required reason.
- **Nominee relations** — resource in the Settings cluster (create/edit/deactivate, T1).
- **Approval chain** — ordered multi-select on the Somiti profile page (T2).
- **Member form** — nominee relation becomes a select of active relations, NID required, at least
  one nominee. Existing members with old data: the form shows the legacy text and requires a
  choice only when saving.
- All actions use `->action()`, are registered in `ActionInventoryTest` with their tier, and the
  policy `MemberApplicationPolicy` decides visibility (no inline role checks).

## 10. Member journeys

### 10.1 Mobile (Flutter, existing GetX conventions)
```text
Splash ── token? ── no ──► Login
   │ yes
   ▼
GET auth/me ── member ────► Dashboard (unchanged)
   │ applicant
   ▼
Registration status ── next_action complete/resubmit ──► Registration form
   ▲                                                       (5 steps, draft saved on Next)
   └──────────── submit (confirm dialog, idempotency_key) ◄┘
Approved: 401 → refresh → member token → Dashboard
```
- New module `lib/modules/registration/` (bindings, controller, model, repository, view).
- Form steps: 1 Personal (names, guardian, NID, date of birth, photo) · 2 Contact (mobile shown
  read-only, email, address) · 3 Nominees (repeatable, relation dropdown from
  `config/nominee-relations`, NID required, running share total) · 4 Shares requested · 5 Review
  (all data, Edit link per section) → `AppConfirmationDialog` → submit.
- Status page: headline + message from the server, vertical timeline (✓ done, ● pending,
  ○ waiting, ✕ returned/rejected), decision reason, one main button per `next_action`,
  pull-to-refresh, logout.
- New shared widget `core/widgets/app_step_indicator.dart`.
- Login and splash route by `account_type`. All texts in `en_us.dart` / `bn_bd.dart`.

### 10.2 Web portal (`/portal`)
Same journey. Applicants see only **Registration** (Filament Wizard with the same 5 steps, T2
summary before submit) and **Registration status** (same timeline). After approval the next page
load shows the normal member dashboard.

## 11. Edge cases

| Case | Behaviour |
|------|-----------|
| Existing member signs in | Member lookup runs first — nothing changes. |
| Mobile already a member / open application | Invite refused with a clear message. |
| Applicant opens an old app build | Member endpoints return 403 with an "update the app" message; no logout loop. |
| Session/token expired | Existing refresh flow; ability re-evaluated on refresh. |
| Double tap on Submit / retry after network failure | Same `idempotency_key` → same result; row lock + status check. |
| Network failure mid-form | Draft already saved on each Next; local field values kept on the page. |
| Partially filled data | `GET registration` returns the draft; the form starts from it. |
| Approver tries to approve twice / two steps | Refused (R4). |
| Two approvers act at the same moment | Row lock; the second sees the new state and gets a rule message. |
| Approval chain changed while submissions are open | Open submissions keep their snapshot (R9). |
| Role at the current step has no active user | The application waits; visible in the list filter. UNKNOWN whether a fallback is needed. |
| Office needs a correction | Return for correction with a reason (R8). |
| No approved rate plan for the chosen month | Final approval refused by the existing rule; nothing saved. |
| Rejected applicant tries to sign in | Sign-in fails with the normal message. |
| Rejected person needs a new chance | Office invites the same mobile again (new application). |
| Member exited later | Existing exit flow; unrelated to the application. |

## 12. Open items — UNKNOWN, requires business confirmation

1. **SMS wording** (bn/en) for `registration_returned` and `registration_rejected`. Proposed
   defaults will be seeded and are editable in SMS templates.
2. **Initial relation list and Bangla labels** (proposed: পিতা, মাতা, স্বামী/স্ত্রী, পুত্র, কন্যা,
   ভাই, বোন, অন্যান্য).
3. **Invitation expiry**: should an `invited` login that is never submitted expire? Proposed: no
   expiry in this version.
4. **Fallback approver** when no user holds the role at the current step. Proposed: none; the
   super admin edits the chain for future submissions.
5. **Should the invite SMS the password to the member?** Proposed: no (the office tells the member
   in person), to avoid sending passwords by SMS.

## 13. Delivery order and testing

1. Nominee relations + `NomineeRules` (office form included) — tests for the form and rules.
2. Application tables, enum, Actions (invite, save, submit, decide) — Pest per Action: happy path,
   each violation, idempotent submit, concurrency (`inParallel`), wrong role / same approver;
   invariant: no member/dues/journal before final approval.
3. Auth and API — token ability isolation, 403 for applicant on member routes, refresh after
   approval, envelope/locale, `ApiAuthTest` unchanged.
4. Admin resource and actions — Livewire tests, `ActionInventoryTest` tiers.
5. Portal pages — Livewire tests; `PortalTest` unchanged.
6. Mobile — repository tests with `FakeApi` + fixtures, controller tests (draft save, idempotency
   reuse), routing by `account_type`, `flutter analyze`, `flutter test`.

After each backend step: `composer test`, `composer analyse`, `composer format:check`.
Mobile repo docs (`docs/MEMBER_FEATURES.md`, `MEMBER_API_SPECIFICATION.md`,
`MEMBER_NAVIGATION.md`) are updated from this spec once it is approved.
