# Member mobile API and Flutter member app — design

Date: 2026-10-05 · Status: awaiting review

## 1. Purpose

Members already use the web portal (Filament panel `member`). The Flutter app at
`/Users/fazlerabbi/Desktop/Projects/somobay_somiti_mobile_app` must become a mobile view of the same
member capabilities. The backend has **no API** today (no `routes/api.php`, no token auth), so this
work adds a member JSON API to the Laravel app, documents it, and wires the existing Flutter
architecture to it.

**Success:** every member capability of the web portal is available in the app; every number the app
shows comes from the backend unchanged; a member can never read another member's data; the Flutter
architecture (GetX modules, Dio `ApiClient`, `UIState`, translations) is unchanged in shape.

## 2. Decisions (agreed)

| Topic | Decision |
|-------|----------|
| Order | Backend API first (with tests) → six docs in the mobile repo from the real API → Flutter. |
| Money in JSON | Every amount is `{"poisha": int, "display": string}`; `display` is formatted by the backend in the request language. Flutter shows `display` and uses `poisha` only for comparisons. |
| Tokens | Laravel Sanctum. Access token 60 min; refresh token 30 days, single use, rotated on refresh; reuse of a spent refresh token revokes all of that member's tokens. |
| Unsupported mobile modules | Loans, savings/DPS, member list, register, forgot-password, force-update: files kept, routes and menu entries removed, API constants for them removed. |
| Notifications | The member's own SMS history (`sms_messages`), read-only, newest first, login codes excluded. No read/unread, no push. |
| Shares screen | Current shares, share-change history, and the current month's rates from the approved rate plan. |

## 3. Member capabilities (source of truth: the web portal)

| Capability | Web source | API |
|------------|------------|-----|
| Sign in with mobile + password, or SMS code when enabled | `Filament/Member/Pages/Auth/MemberLogin`, `LoginCodes`, `PortalAccounts` | `auth/*` |
| Dashboard: savings, advance, outstanding, paid-through, months the advance covers, shares, recent payments | `Member/Pages/Dashboard`, `MemberSummary`, `RecentPaymentsWidget` | `dashboard/summary` |
| Dues list (filter status/type) | `Member/Pages/Dues` | `dues` |
| Payments list, receipt PDF for approved ones | `Member/Pages/Payments`, `ReceiptDocument` | `payments`, `payments/{id}`, `payments/{id}/receipt` |
| Pay online (bKash/Nagad, amount, TrxID, date, proof) → pending | `Member/Pages/PayOnline`, `RecordPayment` | `POST payments` |
| Statement for a date range + PDF | `Member/Pages/Statement`, `MemberStatementReport` | `statement`, `statement/pdf` |
| Dividends | `Member/Pages/Dividends` | `dividends` |
| Profile + nominees (read-only), change password | `Member/Pages/Profile`, `ChangeOwnPassword` | `profile`, `profile/change-password` |
| Society details | `SomitiProfile` | `config/somiti-info` |
| Share position and current rates | `ShareRegister`, `ShareTransaction`, `RateResolver` | `shares/overview` |
| SMS history | `sms_messages` | `notifications` |

**Not supported by the backend for members (documented, not built):** self-registration, forgot /
reset password (staff set member passwords), loans, savings/DPS products, member directory,
notification read state, push, app version check, editing profile fields.

## 4. API

Base path `/api/v1`, routes in a new `routes/api.php` (registered in `bootstrap/app.php`).
Controllers in `app/Http/Controllers/Api/Member/*` are thin: validate (Form Request), call the domain
service or Action the portal uses, return an API Resource. No business logic in controllers.

### 4.1 Envelope

Every response, success or error, matches the app's `ApiResponse`:

```json
{ "success": true, "statusCode": 200, "message": "OK", "data": { }, "errors": null, "meta": null }
```

Paginated lists put Laravel's paginator figures in `meta`:
`{"current_page": 1, "last_page": 3, "per_page": 20, "total": 47}` (`per_page` fixed at 20).
Errors: `success: false`, localised `message`, and `errors` as `{"field": ["message"]}` for 422.
Status codes: 401 unauthenticated / revoked, 403 not a member, 404 not found or not yours, 409
idempotency conflict, 422 validation or `DomainRuleViolation`, 429 rate limited, 500 (generic message).

### 4.2 Value formats

- Money: `{"poisha": 100525, "display": "৳১,০০৫.২৫"}` (bn) or `"৳1,005.25"` (en), via `Display::money`.
- Dates `YYYY-MM-DD`; months `YYYY-MM`; timestamps ISO-8601 with offset (Asia/Dhaka).
- Enums: `{"value": "open", "label": "<localised>", "color": "<filament color>"}`.
- Language: `Accept-Language: bn|en` (default bn) sets the app locale for the request.

### 4.3 Endpoints

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `auth/login` | — | `{mobile, password}` or `{mobile, code}` (code only when `somiti.portal_otp`); same checks and timebox as `MemberLogin`; returns `{access_token, refresh_token, token_type: "Bearer", expires_in: 3600}` |
| POST | `auth/send-code` | — | `{mobile}`; 404 when codes are off; limits from `LoginCodes` |
| POST | `auth/refresh-token` | refresh token in body | `{refresh_token}` → new pair; old pair revoked |
| POST | `auth/logout` | access | revokes this access token and its refresh token |
| GET | `config/somiti-info` | — | names bn/en, display name, address, phone, email, registration no., `logo_url` (signed, 1 h) or null, `otp_enabled` |
| GET | `dashboard/summary` | access | `MemberSummary` fields + `pay_now_visible` + latest 5 payments + member name/number |
| GET | `dues` | access | `status`, `type` filters; default `status=open`; ordered month desc, id |
| GET | `payments` | access | `status` filter; ordered received_on desc |
| GET | `payments/{id}` | access | 404 unless the member's; includes allocations (due month, type, amount) and advance part |
| GET | `payments/{id}/receipt` | access | `{url}` signed receipt URL; 404 unless approved and the member's |
| POST | `payments` | access | multipart: `method` (bkash/nagad), `amount` (taka text, ≤ 2 decimals), `trx_id`, `received_on`, `proof` (jpeg/png/webp/pdf, ≤ `somiti.max_proof_kb`), `idempotency_key` (uuid); calls `RecordPayment` exactly as `PayOnline`; 201 with the payment |
| GET | `statement` | access | `from`, `until` (defaults from `MemberStatementReport::defaults()`); returns its lines and totals |
| GET | `statement/pdf` | access | same filters; `application/pdf` download |
| GET | `dividends` | access | paginated dividend lines with fiscal year code, share-months, amount, status |
| GET | `shares/overview` | access | current shares; history (`ShareTransaction`: type, shares, shares after, effective month, reason); current month's plan: share unit, service charge per share, registration fee per share, due day, grace days, late fee rule (as shown in the portal's rate labels) |
| GET | `profile` | access | member fields shown in the portal profile + nominees (name, relation, share %) |
| POST | `profile/change-password` | access | `{current_password, password, password_confirmation}` → `ChangeOwnPassword`; revokes the member's other tokens |
| GET | `notifications` | access | paginated SMS log for the member: template key/label, body, status, sent_at; excludes `login_code` |

Exact field lists per resource are written into `MEMBER_API_MODELS.md` from the implemented
Resources (generated from code, not from this table).

### 4.4 Authentication and authorization

- Sanctum installed; `personal_access_tokens` with abilities: access tokens carry `member`, refresh
  tokens carry only `refresh`. A refresh token cannot call data endpoints; an access token cannot refresh.
- Middleware `auth:sanctum` + `ability:member` + a `EnsureActiveMember` middleware resolving the member
  with `PortalAccounts::activeMemberOf($user)`; null → 403 and all tokens revoked.
- Every query is scoped to that member id; route-bound ids (`payments/{id}`) are looked up within the
  member's own rows (404 otherwise). No endpoint accepts a member id.
- Staff users cannot obtain or use member tokens (login only resolves members by mobile, as the portal does).
- Rate limits: `auth/login` 5/min per mobile+IP; `auth/refresh-token` 10/min per IP; data endpoints
  60/min per token.
- Deactivating or exiting a member, or staff setting the member's portal password, revokes the member's tokens.

## 5. Flutter app

Keep the existing structure: `lib/modules/<feature>/{bindings,controller,model,repository,view}`,
GetX bindings/routes, `ApiClient` + `AuthInterceptor`, `UIState<T>`, `error_handler`/`failures`,
`app_translations` with `en_us.dart`/`bn_bd.dart`/`translation_keys.dart`, `@JsonSerializable` models
with build_runner.

- `ApiConstants`: replace with the real endpoints from §4.3; remove unsupported ones.
- New shared `MoneyModel` (`poisha` int, `display` String) and `EnumValueModel` in `lib/core/models`.
- Modules: `authentication` (password + optional code), `home` (dashboard summary, pay now, links to
  shares and notifications), new `dues`, new `payments` (list, detail, receipt via `url_launcher`,
  pay-online form with proof picker), `transactions` reused as **Statement** (passbook tab, PDF),
  `share_capital` (shares overview), `notifications` (SMS history), `profile_settings` (profile,
  nominees, dividends, society info, language, change password, logout).
- Bottom navigation (same widget): Home · Dues · Payments · Passbook (statement) · Profile.
- Hidden: loans, savings_dps, members, register, forgot-password, force-update (routes/menu removed,
  files kept).
- Strings: every user-facing string as a translation key in both `en_us.dart` and `bn_bd.dart`.
  Errors mapped by status code (no internet, timeout, 401, 403, 404, 422 field errors, 429, 5xx, unknown).
- The proof upload needs a picker; if no image/file picker package exists, adding `file_picker` is the
  one dependency change (needs approval at plan time).

## 6. Documentation (mobile repo `docs/`)

`MEMBER_BUSINESS_RULES.md`, `MEMBER_API_SPECIFICATION.md`, `MEMBER_API_MODELS.md`,
`MEMBER_FEATURES.md`, `MEMBER_NAVIGATION.md`, `MEMBER_ACCOUNTING_RULES.md`, written after the API
exists, using the structures in the brief, citing backend sources (file paths), with request/response
examples captured from the feature tests. Anything not supported is listed under
"UNKNOWN / REQUIRES BACKEND CLARIFICATION" or "Not supported".

## 7. Testing

Backend (Pest): per endpoint happy path, validation, envelope shape, money format in bn and en;
cross-member access (member A with B's payment id → 404; lists never contain B's rows); staff and
deactivated members refused; token lifecycle (expiry, rotation, reuse revokes all, logout, password
change revokes others); pay-online idempotency (same key → same payment; same key different amount →
409); rate limits.

Flutter: model `fromJson` tests against JSON fixtures copied from the backend tests; repository tests
with a mocked Dio adapter for success and each error class; `flutter analyze` clean.

## 8. Out of scope

New backend member features (self-service profile edits, forgot password, push, notices, loans,
savings products); changes to the web portal; app store release.
