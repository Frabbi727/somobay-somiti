# Member Mobile App (plan 2 of 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Document the member business and API from the real backend, then wire the existing Flutter app to the member API so every portal capability works on the phone.

**Architecture:** Existing GetX structure kept exactly: `lib/modules/<feature>/{bindings,controller,model,repository,view}`, `ApiClient` + `AuthInterceptor`, `UIState<T>`, `ErrorHandler`/`Failure`, `.tr` translations in `en_us.dart`/`bn_bd.dart` keyed by `TranslationKeys`. Repositories return Dart records `({Failure? failure, T? data})` as today, but call the real API instead of returning mock data. Money is `MoneyModel(poisha, display)`; the app shows `display` and never computes amounts.

**Tech Stack:** Flutter 3.44 / Dart 3.12, GetX 4.6, Dio 5.7, json_serializable, url_launcher (existing), file_picker (new, approved).

**Spec:** `docs/superpowers/specs/2026-10-05-member-mobile-api-design.md` (§3, §5, §6). The API it calls is built and tested (plan 1, commits `16e977a..8094000`); `tests/Feature/Api/*` are the contract.

**Repos:** backend `/Users/fazlerabbi/Desktop/Projects/tonmoy/somobay-somiti` (Task 0 only); mobile `/Users/fazlerabbi/Desktop/Projects/somobay_somiti_mobile_app` (everything else). Mobile work happens on a new branch `member-api` in the mobile repo.

## Global Constraints

- Do not change the mobile architecture: no new state management, router, network client or DI style.
- Every user-facing string is a `TranslationKeys` constant with entries in both `en_us.dart` and `bn_bd.dart`; no Bangla or English literals in views (fix the literals in any view this plan touches).
- No `double` for money anywhere new. Amounts come from the API as `{poisha, display}`; show `display`. Only comparisons use `poisha` (int).
- No endpoint not in `ApiConstants`, and no `ApiConstants` entry that the backend does not have.
- The member's id is never sent; the token identifies the member.
- Models use `@JsonSerializable()` + `part '*.g.dart'`; regenerate with `dart run build_runner build --delete-conflicting-outputs`.
- After each task: `flutter analyze` (no new issues) and `flutter test`.

## Review Focus

1. **Session expiry during use** — an access token expires mid-session: the interceptor refreshes once and retries; if refresh fails the member lands on login with a localised "please sign in again", never a raw error or a loop of refresh calls. (Task 2.)
2. **Bangla digits typed into amount / mobile / code fields** — accepted and converted before sending (the backend also accepts them for amounts). (Tasks 3, 6.)
3. **Pay-online double tap or retry after a timeout** — one idempotency key per form; the second submit returns the same payment, never a duplicate. (Task 6.)
4. **Language switch** — changing language re-requests data so `display` money and labels follow the new language. (Task 8.)
5. **Empty member** (new member, no dues, no payments, no dividends, no SMS) — every list shows its localised empty state, not an error. (Tasks 4–8.)

---

### Task 0 (backend): signed link for the statement PDF

The app opens PDFs with `url_launcher`, which cannot send the bearer token. Receipts already use a signed URL; the statement needs the same.

**Files (backend repo):**
- Modify: `app/Http/Controllers/Api/Member/StatementController.php`, `routes/api.php`
- Test: `tests/Feature/Api/ApiStatementTest.php`

**Interfaces:**
- Produces: `GET /api/v1/statement/pdf-link?from=&until=` → `{"url": "<signed, 15 min>"}`; `GET /api/v1/statement/pdf-signed` (signed middleware, no token) streams the PDF for the member id embedded in the signature.

- [ ] **Step 1: Failing test** (append to `ApiStatementTest.php`):

```php
it('gives a short-lived signed link to the statement PDF that works without a token', function (): void {
    $url = $this->withToken($this->token)->getJson('/api/v1/statement/pdf-link?from=2026-07-01&until=2026-07-31')->assertOk()->json('data.url');

    app('auth')->forgetGuards();
    $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(str_replace('until=2026-07-31', 'until=2026-08-31', $url))->assertForbidden();

    $this->travel(16)->minutes();
    $this->get($url)->assertForbidden();
});
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Api/ApiStatementTest.php` — Expected: FAIL (404).

- [ ] **Step 3: Implement** in `StatementController`:

```php
    public function pdfLink(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return ApiResponse::ok(['url' => URL::temporarySignedRoute('api.statement.signed', now()->addMinutes(15), $filters)]);
    }

    public function pdfSigned(Request $request, ReportExporter $exporter): Response
    {
        $filters = ['member' => $request->integer('member'), 'from' => (string) $request->string('from'), 'until' => (string) $request->string('until')];
        $content = $exporter->pdf($this->report, $filters);
        abort_if($content === null, 404);

        return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->report->filename($filters).'.pdf"']);
    }
```

Routes: inside the authenticated group `Route::get('statement/pdf-link', [StatementController::class, 'pdfLink']);`; inside the `v1` group but outside auth: `Route::get('statement/pdf-signed', [StatementController::class, 'pdfSigned'])->middleware('signed')->name('api.statement.signed');`. The member id is inside the signed query, so it cannot be changed. Add `statement/pdf-signed` to the isolation sweep's exclusions (it is token-free by design, protected by signature) — edit `memberApiRoutes()` in `tests/Feature/Api/ApiIsolationTest.php` to also skip `api/v1/statement/pdf-signed`.

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse` — Expected: PASS.
- [ ] **Step 5: Commit** (backend): `Member API: signed link for the statement PDF`.

---

### Task 1: The six member documents (then stop for review)

**Files (mobile repo):** Create `docs/MEMBER_BUSINESS_RULES.md`, `docs/MEMBER_API_SPECIFICATION.md`, `docs/MEMBER_API_MODELS.md`, `docs/MEMBER_FEATURES.md`, `docs/MEMBER_NAVIGATION.md`, `docs/MEMBER_ACCOUNTING_RULES.md`.

**Sources, in order of authority:** the backend code and its tests (`tests/Feature/Api/*`, `tests/Feature/Portal/*`, domain Actions), then `SOMITI_SPEC.md`, then the design spec. Every rule cites the backend file it comes from. Anything not supported is written as "Not supported" with the reason; anything unclear as `UNKNOWN / REQUIRES BACKEND CLARIFICATION`.

- [ ] **Step 1: Capture real responses.** In the backend repo, add a temporary Pest file `tests/Feature/Api/CaptureExamplesTest.php` that builds a realistic member (2 shares, July+August dues, one approved cash payment with surplus to advance, one pending bKash payment, nominees, an SMS) and writes each endpoint's JSON (Accept-Language bn and en) to `storage/app/api-examples/<name>.<lang>.json`. Run it, copy the files into the mobile repo's `docs/examples/`, then delete the test file (it is a capture tool, not a test). These files are the request/response examples in the docs.

- [ ] **Step 2: Write `MEMBER_BUSINESS_RULES.md`** with the brief's per-feature template (Purpose / Business Rules / Preconditions / User Flow / Validation / Success / Failure / Edge cases / Backend Source) for: sign-in (password, SMS code), session and sign-out, dashboard summary, dues, payments and receipts, pay online, statement, dividends, shares and rates, profile and nominees, change password, SMS history, society info. Include the rules the backend actually enforces, e.g. exited members cannot sign in (inactive can); pay online only bKash/Nagad with TrxID `^[A-Za-z0-9]{6,40}$` and proof ≤ 2 MB (jpeg/png/webp/pdf), pending until staff approve, approver ≠ recorder; registration fee charged per share at the effective month's rate; dues generated on the 1st; late fee per BR-10/11; advance applied oldest-first; paid-through is calculated, never stored.

- [ ] **Step 3: Write `MEMBER_API_SPECIFICATION.md`**: every endpoint in `routes/api.php` with method, path, auth, headers (`Authorization`, `Accept-Language`, `Accept: application/json`), parameters, request, response (from `docs/examples`), validation (from the Form Requests), errors (401/403/404/405/409/422/429/500 and their envelope), pagination (`meta`, 20 per page), business rules.

- [ ] **Step 4: Write `MEMBER_API_MODELS.md`**: one table per model (Field | JSON key | Dart type | Nullable | Description | Example) — `ApiResponse<T>`, `PaginationMeta`, `MoneyModel`, `EnumValueModel`, `AuthTokenModel`, `SomitiInfoModel`, `DashboardSummaryModel`, `PaymentSummaryModel`, `PaymentDetailModel`, `PaymentAllocationModel`, `DueModel`, `StatementModel`, `StatementRowModel`, `DividendModel`, `SharesOverviewModel`, `ShareChangeModel`, `RatesModel`, `LateFeeModel`, `ProfileModel`, `NomineeModel`, `SmsNotificationModel`. Dates are `String` (`YYYY-MM-DD`), months `String` (`YYYY-MM`), timestamps `String` (ISO-8601); list every enum value from the backend enums.

- [ ] **Step 5: Write `MEMBER_FEATURES.md`** (Feature / Purpose / API / Model / User action / Expected result / Errors) and **`MEMBER_NAVIGATION.md`** (bottom tabs Home · Dues · Payments · Passbook · Profile; secondary pages; which existing routes are hidden and why).

- [ ] **Step 6: Write `MEMBER_ACCOUNTING_RULES.md`** with three columns per rule — *Backend calculates* / *Mobile displays* / *Mobile calculates* (always "nothing" except comparisons). Cover share calculation, monthly deposit and service charge, registration fee and the difference policy, late fee, advance, allocation order, outstanding, paid-through, statement balance, dividends by share-months, refunds and reversals (staff-only, visible to the member only as status changes).

- [ ] **Step 7: Commit (mobile, branch `member-api`)**: `docs: member business rules, API spec and models from the backend`.

- [ ] **Step 8: STOP.** The brief requires the documents to be reviewed before implementation. Ask the user to review `docs/` and wait for approval; apply requested changes before Task 2.

---

### Task 2: Core — endpoints, money/enum models, errors, interceptor, local base URL

**Files (mobile):**
- Modify: `lib/core/constants/api_constants.dart`, `lib/core/errors/error_handler.dart`, `lib/core/errors/failures.dart`, `lib/core/network/interceptors/auth_interceptor.dart`, `lib/main_development.dart`, `lib/app/localization/{translation_keys,en_us,bn_bd}.dart`
- Create: `lib/core/models/money_model.dart`, `lib/core/models/enum_value_model.dart`
- Test: `test/core/money_model_test.dart`, `test/core/error_handler_test.dart`

**Interfaces:**
- Produces: `MoneyModel {int poisha; String display; bool get isPositive}`; `EnumValueModel {String value; String label; String? color}`; `ApiConstants` entries named after §4.3 (`login`, `sendCode`, `refreshToken`, `logout`, `me`, `somitiInfo`, `dashboardSummary`, `dues`, `payments`, `paymentDetail(int id)`, `paymentReceipt(int id)`, `statement`, `statementPdfLink`, `dividends`, `sharesOverview`, `profile`, `changePassword`, `notifications`); `ForbiddenFailure`, `NotFoundFailure`, `ConflictFailure`, `RateLimitFailure` (all `Failure`).

- [ ] **Step 1: Failing tests**

```dart
// test/core/money_model_test.dart
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/core/models/money_model.dart';

void main() {
  test('reads poisha exactly and keeps the backend display text', () {
    final money = MoneyModel.fromJson({'poisha': 100525, 'display': '৳১,০০৫.২৫'});
    expect(money.poisha, 100525);
    expect(money.display, '৳১,০০৫.২৫');
    expect(money.isPositive, isTrue);
    expect(MoneyModel.fromJson({'poisha': 0, 'display': '৳০.০০'}).isPositive, isFalse);
  });
}
```

```dart
// test/core/error_handler_test.dart
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/core/errors/error_handler.dart';
import 'package:somobay_somiti_mobile_app/core/errors/failures.dart';

DioException respond(int status, Map<String, dynamic> body) => DioException(
      requestOptions: RequestOptions(path: '/x'),
      type: DioExceptionType.badResponse,
      response: Response(requestOptions: RequestOptions(path: '/x'), statusCode: status, data: body),
    );

void main() {
  test('maps every API status to a member-friendly failure with the backend message', () {
    expect(ErrorHandler.handleException(respond(401, {'message': 'x'})), isA<AuthenticationFailure>());
    expect(ErrorHandler.handleException(respond(403, {'message': 'নেই'})), isA<ForbiddenFailure>());
    expect(ErrorHandler.handleException(respond(404, {'message': 'পাওয়া যায়নি।'})).message, 'পাওয়া যায়নি।');
    expect(ErrorHandler.handleException(respond(409, {'message': 'c'})), isA<ConflictFailure>());
    expect(ErrorHandler.handleException(respond(429, {'message': 't'})), isA<RateLimitFailure>());
    final validation = ErrorHandler.handleException(respond(422, {'message': 'm', 'errors': {'amount': ['bad']}})) as ValidationFailure;
    expect(validation.validationErrors?['amount'], ['bad']);
    expect(ErrorHandler.handleException(respond(500, {'message': 'boom'})).message, 'error_server');
  });
}
```

- [ ] **Step 2: Run** `flutter test test/core` — Expected: FAIL (missing classes).

- [ ] **Step 3: Implement**

`lib/core/models/money_model.dart`:

```dart
import 'package:json_annotation/json_annotation.dart';

part 'money_model.g.dart';

/// An amount from the API: exact poisha plus the backend's own formatted text.
/// The app shows [display]; it never does arithmetic on money.
@JsonSerializable()
class MoneyModel {
  final int poisha;
  final String display;

  const MoneyModel({required this.poisha, required this.display});

  bool get isPositive => poisha > 0;

  factory MoneyModel.fromJson(Map<String, dynamic> json) => _$MoneyModelFromJson(json);
  Map<String, dynamic> toJson() => _$MoneyModelToJson(this);
}
```

`lib/core/models/enum_value_model.dart`: same style with `String value`, `String label`, `String? color`. (`value` can be int for some enums in the backend; all member-facing enums are string-backed — confirm in Task 1 and note it in the models doc.)

`failures.dart`: add

```dart
class ForbiddenFailure extends Failure {
  const ForbiddenFailure({String? message}) : super(message: message ?? 'error_forbidden', statusCode: 403);
}

class NotFoundFailure extends Failure {
  const NotFoundFailure({String? message}) : super(message: message ?? 'error_not_found', statusCode: 404);
}

class ConflictFailure extends Failure {
  const ConflictFailure({String? message}) : super(message: message ?? 'error_conflict', statusCode: 409);
}

class RateLimitFailure extends Failure {
  const RateLimitFailure({String? message}) : super(message: message ?? 'error_too_many', statusCode: 429);
}
```

`error_handler.dart` `badResponse` branch: keep the parsing; replace the status switch with

```dart
        switch (statusCode) {
          case 401:
            return const AuthenticationFailure();
          case 403:
            return ForbiddenFailure(message: message);
          case 404:
            return NotFoundFailure(message: message);
          case 409:
            return ConflictFailure(message: message);
          case 422:
            return ValidationFailure(message: message, errors: validationErrors);
          case 429:
            return RateLimitFailure(message: message);
          default:
            return ServerFailure(message: (statusCode ?? 500) >= 500 ? 'error_server' : message, statusCode: statusCode);
        }
```

The backend already localises `message` (Accept-Language), so 4xx messages are shown as-is (`.tr` on an unknown key returns the text unchanged); 5xx uses the app's own key so nothing technical leaks.

`auth_interceptor.dart`: do not try to refresh when the failing request *is* `login`, `send-code` or `refresh-token`; use `ApiConstants.refreshToken`; read tokens from `response.data['data']`; guard with a static `_refreshing` future so concurrent 401s share one refresh; after a failed refresh call `clearAuthData()` and `Get.offAllNamed(AppRoutes.login)` once. Keep it a `QueuedInterceptor`.

`api_constants.dart`: replace the whole class body with the endpoints listed in Interfaces (all `/api/v1/...`), e.g. `static String paymentDetail(int id) => '/api/v1/payments/$id';`.

`main_development.dart`: `apiBaseUrl: const String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8000')` (Android emulator → host). Document `--dart-define=API_BASE_URL=http://localhost:8000` for iOS simulator in `docs/MEMBER_NAVIGATION.md`'s run section.

Translations: add `error_forbidden`, `error_not_found`, `error_conflict`, `error_too_many`, `error_session_expired` (check existing keys first) in both languages.

- [ ] **Step 4:** `dart run build_runner build --delete-conflicting-outputs && flutter test test/core && flutter analyze` — Expected: PASS, no new issues.
- [ ] **Step 5: Commit**: `core: real API endpoints, money model, status-aware errors, single-flight token refresh`.

---

### Task 3: Sign-in, SMS code, splash and logout

**Files:** Modify `lib/modules/authentication/{model/login_request_model.dart, repository/auth_repository.dart, login/controller/login_controller.dart, login/view/login_page.dart}`, `lib/modules/splash/{repository/splash_repository.dart, controller/splash_controller.dart}`; Create `lib/modules/profile_settings/model/somiti_info_model.dart` (used by splash and Task 8); Test `test/modules/auth_repository_test.dart`.

**Interfaces:**
- `LoginRequestModel {String mobile; String? password; String? code}` → JSON `{mobile, password?, code?}` (`includeIfNull: false`).
- `IAuthRepository`: `login(LoginRequestModel) → ({Failure? failure, AuthTokenModel? tokens})`, `sendCode(String mobile) → ({Failure? failure, bool isSuccess})`, `logout() → ({Failure? failure, bool isSuccess})`. Remove `register`, `forgotPassword`, `verifyOtp`.
- `ISplashRepository`: `somitiInfo() → ({Failure? failure, SomitiInfoModel? info})`, `me() → ({Failure? failure, bool isValid})`.

- [ ] **Step 1: Failing repository test** using a fake Dio adapter (no new package):

```dart
// test/modules/auth_repository_test.dart
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/modules/authentication/model/login_request_model.dart';
import 'package:somobay_somiti_mobile_app/modules/authentication/repository/auth_repository.dart';
import '../support/fake_api.dart';

void main() {
  test('logs in with mobile and password and reads the token pair from the envelope', () async {
    final api = FakeApi({
      'POST /api/v1/auth/login': (body) => (200, {
            'success': true, 'statusCode': 200, 'message': 'OK', 'errors': null, 'meta': null,
            'data': {'access_token': 'a', 'refresh_token': 'r', 'token_type': 'Bearer', 'expires_in': 3600},
          }),
    });

    final result = await AuthRepository(apiClient: api.client).login(const LoginRequestModel(mobile: '01712345678', password: 'secret-123'));

    expect(result.failure, isNull);
    expect(result.tokens?.accessToken, 'a');
    expect(api.lastBody, {'mobile': '01712345678', 'password': 'secret-123'});
  });

  test('turns a wrong password into a validation failure with the backend message', () async {
    final api = FakeApi({
      'POST /api/v1/auth/login': (body) => (422, {'success': false, 'statusCode': 422, 'message': 'm', 'errors': {'mobile': ['ভুল']}, 'data': null, 'meta': null}),
    });

    final result = await AuthRepository(apiClient: api.client).login(const LoginRequestModel(mobile: '01712345678', password: 'x'));

    expect(result.tokens, isNull);
    expect(result.failure?.validationErrors?['mobile'], ['ভুল']);
  });
}
```

`test/support/fake_api.dart` — a reusable fake used by every repository test:

```dart
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:somobay_somiti_mobile_app/core/network/api_client.dart';

typedef Handler = (int, Map<String, dynamic>) Function(dynamic body);

/// Answers Dio requests from a "METHOD /path" → handler map, like the real API envelope.
class FakeApi implements HttpClientAdapter {
  final Map<String, Handler> routes;
  dynamic lastBody;
  Map<String, dynamic>? lastQuery;
  late final ApiClient client;

  FakeApi(this.routes) {
    client = ApiClient.forTesting(Dio(BaseOptions(baseUrl: 'http://test'))..httpClientAdapter = this);
  }

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    lastBody = options.data;
    lastQuery = options.queryParameters;
    final handler = routes['${options.method} ${options.path}'];
    final (status, body) = handler == null ? (404, {'success': false, 'statusCode': 404, 'message': 'not found', 'data': null, 'errors': null, 'meta': null}) : handler(options.data);
    return ResponseBody.fromString(jsonEncode(body), status, headers: {Headers.contentTypeHeader: ['application/json']});
  }

  @override
  void close({bool force = false}) {}
}
```

This needs one small, test-only seam in `ApiClient`: a named constructor `ApiClient.forTesting(Dio dio)` that uses the given Dio without interceptors (add `ApiClient._(this._dio);` and `factory ApiClient.forTesting(Dio dio) => ApiClient._(dio);`, keeping the existing constructor unchanged). `options.path` is the path passed to `get/post`, which is exactly the `ApiConstants` value.

- [ ] **Step 2: Run** `flutter test test/modules/auth_repository_test.dart` — Expected: FAIL.

- [ ] **Step 3: Implement**
  - `AuthRepository.login`: `final response = await apiClient.post(ApiConstants.login, data: request.toJson()); return (failure: null, tokens: AuthTokenModel.fromJson((response.data as Map<String, dynamic>)['data'] as Map<String, dynamic>));` inside the existing try/catch.
  - `sendCode`: `POST ApiConstants.sendCode {mobile}`. `logout`: `POST ApiConstants.logout` then the controller clears storage regardless of the result.
  - `LoginController`: fields `codeController`, `useCode` (RxBool), `otpEnabled` (RxBool, from `SomitiInfoModel` passed by splash via `Get.arguments` or fetched); `sendCode()` with a 60 s resend countdown; `login()` sends either password or code; on `ValidationFailure` show `validationErrors['mobile'|'code'].first`; on `RateLimitFailure` show its message. Convert Bangla digits with the existing `BanglaNumberUtil.toEnglish` for mobile and code.
  - `LoginPage`: keep its layout; remove "register" and "forgot password" links (unsupported — staff set member passwords; show `login_password_help` text "Ask the society office to set or reset your password"); add the password/code toggle only when `otpEnabled`.
  - `SplashRepository`: `somitiInfo()` → `GET ApiConstants.somitiInfo`; `me()` → `GET ApiConstants.me` (true on 200, false on `AuthenticationFailure`/`ForbiddenFailure`).
  - `SplashController`: drop the version check (no backend support; force-update route hidden in Task 9); fetch somiti info (store name for the app bar via `StorageService.saveString(StorageKeys.somitiName, …)`), then: no token → login; token → `me()`; valid → dashboard; else clear tokens → login. Network failure with a token present → dashboard (screens show their own offline state).

- [ ] **Step 4:** `dart run build_runner build --delete-conflicting-outputs && flutter test && flutter analyze`.
- [ ] **Step 5: Commit**: `auth: real sign-in with password or SMS code, session check on start, logout`.

---

### Task 4: Home (dashboard summary)

**Files:** Replace `lib/modules/home/model/somiti_summary_model.dart` with `dashboard_summary_model.dart` (+ `payment_summary_model.dart` in `lib/modules/payments/model/`, created here because Home lists recent payments); modify `home_repository.dart`, `home_controller.dart`, `home_page.dart`; test `test/modules/home_repository_test.dart`.

**Interfaces:**
- `DashboardSummaryModel {MemberBrief member; MoneyModel savings; MoneyModel advance; MoneyModel outstanding; String? paidThrough; int advanceMonthsEstimate; int shares; bool payNowVisible; List<PaymentSummaryModel> recentPayments}` with JSON keys `member, savings, advance, outstanding, paid_through, advance_months_estimate, shares, pay_now_visible, recent_payments`; `MemberBrief {String memberNo (member_no); String name; EnumValueModel status}`.
- `PaymentSummaryModel {int id; String receivedOn (received_on); EnumValueModel method; String? trxId (trx_id); MoneyModel amount; EnumValueModel status}`.
- `IHomeRepository.getDashboardSummary() → ({Failure? failure, DashboardSummaryModel? summary})`.

- [ ] **Step 1: Failing test** with `FakeApi` returning the captured `docs/examples/dashboard_summary.bn.json` (copy into `test/fixtures/`), asserting `summary.outstanding.poisha`, `summary.recentPayments.first.amount.display`, `summary.paidThrough`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the models (`@JsonSerializable(explicitToJson: true)`), repository `GET ApiConstants.dashboardSummary`, controller (unchanged shape; rename state type), page: greeting with `member.name` and `member_no`; cards for savings, advance, outstanding (with "Pay now" button → payments/pay-online when `payNowVisible`), paid-through (`home_paid_through` + month formatted via `BanglaNumberUtil` for bn), advance-covers-about-N-months estimate (labelled as an estimate, as in the portal), shares (tap → Shares page), recent payments list (tap → payment detail), link to notifications in the app bar. Remove loans/savings tiles. Pull to refresh.
- [ ] **Step 4:** build_runner, `flutter test`, `flutter analyze`.
- [ ] **Step 5: Commit**: `home: dashboard summary from the member API`.

---

### Task 5: Dues tab

**Files:** Create `lib/modules/dues/{bindings/dues_binding.dart, controller/dues_controller.dart, model/due_model.dart, repository/dues_repository.dart, view/dues_page.dart}`; test `test/modules/dues_repository_test.dart`.

**Interfaces:**
- `DueModel {int id; String month; EnumValueModel type; MoneyModel amount; MoneyModel paid; MoneyModel outstanding; String dueDate (due_date); EnumValueModel status}`.
- `IDuesRepository.getDues({int page = 1, String status = 'open', String? type}) → ({Failure? failure, List<DueModel> dues, PaginationMeta? meta})`.
- `DuesController`: `duesState`, `items` (RxList), `meta`, `status` filter (`open` | `all` | `paid` | `waived` …from the backend enum), `type` filter, `loadMore()`, `refresh()`.

- [ ] **Step 1: Failing test**: FakeApi returns two pages; assert page 2 is requested with `page=2&status=open`, items appended, `meta.hasMore` false at the end; and an empty `data: []` gives an empty list without a failure.
- [ ] **Step 2–3:** implement with `AppPaginationView`; each row shows month, type label, amount/paid/outstanding `display`, due date, status chip coloured by `status.color`; filter chips use backend enum labels from the response where possible, else translation keys.
- [ ] **Step 4–5:** test, analyze, commit `dues: member dues tab with filters and paging`.

---

### Task 6: Payments tab, detail, receipt, pay online

**Files:** Create `lib/modules/payments/{bindings/payments_binding.dart, controller/payments_controller.dart, controller/pay_online_controller.dart, model/payment_detail_model.dart, model/payment_allocation_model.dart, repository/payments_repository.dart, view/payments_page.dart, view/payment_detail_page.dart, view/pay_online_page.dart}`; add `file_picker` to `pubspec.yaml`; tests `test/modules/payments_repository_test.dart`.

**Interfaces:**
- `PaymentDetailModel` = summary fields + `String? rejectionReason (rejection_reason)`, `String? approvedAt (approved_at)`, `bool receiptAvailable (receipt_available)`, `List<PaymentAllocationModel> allocations`, `MoneyModel toAdvance (to_advance)`.
- `PaymentAllocationModel {int dueId (due_id); String month; EnumValueModel type; MoneyModel amount}`.
- `IPaymentsRepository`: `getPayments({int page, String? status})`, `getPayment(int id)`, `receiptUrl(int id) → ({Failure? failure, String? url})`, `submit({required String method, required String amount, required String trxId, required String receivedOn, required String proofPath, required String idempotencyKey}) → ({Failure? failure, PaymentDetailModel? payment})` (multipart via `FormData` + `MultipartFile.fromFile`).
- `PayOnlineController`: creates one `Uuid().v4()` idempotency key when the form opens and reuses it for every retry of that form; new key only after success.

- [ ] **Step 1: Failing tests**: (a) detail parses allocations and `to_advance`; (b) submit sends multipart fields `method, amount, trx_id, received_on, idempotency_key, proof` and the same key on a retry; (c) a 409 becomes `ConflictFailure`; (d) a 422 exposes field errors.
- [ ] **Step 2–3: Implement.** Pay-online form: method (bKash/Nagad radio), amount (text, Bangla digits allowed, no client-side arithmetic; validate only "not empty" — the backend validates the rest and returns field errors), TrxID (uppercase as typed), date (default today, `YYYY-MM-DD`), proof (file_picker: jpg/png/webp/pdf; show name and size; reject > 2 MB client-side with `pay_proof_too_large`), a summary confirm dialog (like the portal's T2 confirmation) listing method, amount as typed, TrxID, date; submit → success snackbar using the backend `message`, back to the list. Receipt: `receiptUrl` then `launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication)`.
- [ ] **Step 4–5:** `flutter pub get`, build_runner, test, analyze; commit `payments: list, detail, receipts and pay online with proof`.

---

### Task 7: Passbook = statement

**Files:** Replace `lib/modules/transactions/model/transaction_model.dart` with `statement_model.dart` (`StatementModel {String from; String until; MoneyModel opening; List<StatementRowModel> rows; MoneyModel totalCharges (total_charges); MoneyModel totalPaid (total_paid); MoneyModel closing}`, `StatementRowModel {String? date; String description; MoneyModel? charge; MoneyModel? paid; MoneyModel? balance}`); rewrite `transaction_repository.dart` (`getStatement({String? from, String? until})`, `statementPdfUrl({String? from, String? until})`), `transaction_controller.dart` (date range state; defaults come from the response's `from`/`until`), `transaction_history_page.dart` (opening balance, rows with charge/paid/balance `display`, totals, closing; date-range picker; "Download PDF" → signed link → `launchUrl`); remove `transaction_details_page.dart` route (rows have no detail endpoint). Test `test/modules/statement_repository_test.dart` (parses rows with null charge/paid; sends `from`/`until` only when set).

- [ ] Steps: failing test → implement → build_runner/test/analyze → commit `passbook: member statement with PDF download`.

---

### Task 8: Shares, notifications (SMS history), profile, dividends, society info, language

**Files:**
- `lib/modules/share_capital/{model/shares_overview_model.dart, repository/shares_repository.dart, controller/shares_controller.dart, bindings/shares_binding.dart, view/share_overview_page.dart}` — current shares, history list (type label, ±shares, shares after, effective month, reason), current rates card (share unit, service charge, registration fee per share, due day, grace days, late-fee rule rendered from `late_fee.mode` label + `fixed.display` or `percent`%, `cap.display`, `frequency.label`), "No rate plan for this month" when `rates` is null.
- `lib/modules/notifications/{model/sms_notification_model.dart, repository/notifications_repository.dart, controller/notifications_controller.dart, bindings/notifications_binding.dart, view/notification_page.dart}` — paginated SMS history: kind label, body, sent time, status chip.
- `lib/modules/profile_settings/{model/profile_model.dart (+ nominee), model/dividend_model.dart, repository/profile_repository.dart, controller/profile_controller.dart, view/profile_page.dart, view/dividends_page.dart (new), view/somiti_info_page.dart, view/change_password_page.dart, view/language_selection_page.dart}` — profile (member no, names, mobile, joined, status, nominees with `share_display`), dividends list, society info from `SomitiInfoModel` (logo via `cached_network_image` on `logo_url`), change password (`current_password`, `password`, `password_confirmation`; success keeps this device signed in and says other devices were signed out), language switch (save locale, `Get.updateLocale`, then re-fetch Home/Profile so `display` and labels follow — Review Focus 4), logout (confirm dialog → `logout()` → clear tokens → login).
- Tests: one repository test per module (shares parse with `rates: null`; notifications paging; profile nominees; change-password 422 field errors).

- [ ] Steps per module: failing test → implement → build_runner/test/analyze; one commit per module: `shares: overview with current rates`, `notifications: SMS history`, `profile: details, nominees, dividends, society info, password, language, logout`.

---

### Task 9: Navigation, hidden modules, translations sweep, run against the backend

**Files:** `lib/modules/dashboard/{view/dashboard_page.dart, bindings/dashboard_binding.dart}`, `lib/app/routes/{app_routes.dart, app_pages.dart}`, translation files; test `test/widget_test.dart` (add a test that the dashboard shows the five localised tabs).

- [ ] **Step 1:** Dashboard tabs: `HomePage`, `DuesPage`, `PaymentsPage`, `TransactionHistoryPage` (Passbook), `ProfilePage`; labels `tab_home`, `tab_dues`, `tab_payments`, `tab_passbook`, `tab_profile`; bindings register Home, Dues, Payments, Transactions(Statement), Profile controllers; remove Savings/Loan registrations.
- [ ] **Step 2:** Routes: remove `register`, `forgotPassword`, `otpVerification`, `forceUpdate`, `newDeposit`, `loanCalculator`, `loanRepay`, `members`, `memberDetails`, `savingsDetails`, `loanDetails`, `transactionDetails` from `AppRoutes`/`AppPages` (files stay); add `payOnline`, `paymentDetail`, `dividends`, `shareOverview` (with `SharesBinding`), `notifications` (with `NotificationsBinding`).
- [ ] **Step 3:** Translations sweep: `grep -rn "'[^']*[ঀ-৿][^']*'" lib/modules lib/core/widgets` and `grep -rn "Text('" lib/modules` — every hit in a file this plan touched becomes a key in both languages; remove unused loan/savings keys only if no remaining file references them.
- [ ] **Step 4:** `flutter test && flutter analyze` — Expected: all pass, no new analyzer issues.
- [ ] **Step 5: Run against the real backend.** In the backend repo: `php artisan migrate:fresh --seed` (the demo seeder from `docs/LOCAL_TESTING.md`), set a demo member's portal password with tinker or the staff panel, `php artisan serve --host=0.0.0.0 --port=8000`. In the mobile repo: `flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000` (Android) or `http://localhost:8000` (iOS). Walk through: sign in → Home numbers equal the web portal's dashboard for the same member → Dues → Payments → submit a bKash payment with a photo → see it pending → approve it as staff in the web panel → it shows approved with a receipt that opens → Passbook PDF opens → Shares → Notifications → Profile → switch language (amounts re-render in English digits) → change password → logout. Record each step's result in the final message; any mismatch with the web portal is a bug to fix before finishing.
- [ ] **Step 6: Commit**: `app: member navigation, hidden unsupported modules, translations`.
