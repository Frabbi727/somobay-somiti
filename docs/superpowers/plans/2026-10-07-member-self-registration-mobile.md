# Member Self-Registration (Flutter App) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An invited member signs in on the app, completes their registration in five steps, submits it, and follows its approval on a status screen. After the final approval they land on the normal dashboard, with no second sign-in.

**Architecture:** One new GetX module `lib/modules/registration/` (bindings, controller, model, repository, view) built in the shape of the existing modules. Splash and login choose the first screen from the backend's `account_type`. Every label, status and role name comes from the API; the app only shows them.

**Tech Stack:** Flutter (Dart ^3.12), GetX 4.6, Dio 5.7, json_serializable, uuid, file_picker, the existing `ImageCompressor`.

**Spec:** `docs/superpowers/specs/2026-10-07-member-self-registration-design.md` (backend repo) §7, §8, §10.1. The API comes from the backend plan `2026-10-07-member-self-registration-backend.md` (Tasks 9-10), which must be deployed first.

**Repo:** `/Users/fazlerabbi/Desktop/Projects/somobay_somiti_mobile_app`, branch `member-api`. Leave the unrelated uncommitted `android/` changes out of every commit (`git add` only the paths listed).

## Global Constraints

- Keep the existing architecture: `GetView<Controller>` pages, `Bindings` with `Get.lazyPut(..., fenix: true)` repositories, `IXRepository` + `XRepository(apiClient:)` returning Dart records `({Failure? failure, T? x})` and never throwing, `UIState<T>`, `ErrorHandler.handleException`.
- Models: `json_serializable` with `@JsonKey(name: 'snake_case')`; regenerate with `dart run build_runner build --delete-conflicting-outputs`.
- Every visible string is a key in `translation_keys.dart` with values in **both** `en_us.dart` and `bn_bd.dart`, shown with `.tr`. Server labels (status, timeline, role, relation) are shown as received — never translated or hard-coded in the app.
- No hard-coded "Secretary"/"President": the timeline comes from `GET /registration`.
- The app does no money arithmetic. The nominee share total is computed in integer hundredths of a percent from the typed text (no `double`).
- Mutating requests that the user can repeat use one `idempotency_key` per form (uuid v4), reused on retry and renewed after success — the `PayOnlineController` pattern.
- After each task: `flutter analyze` clean, `flutter test` green.

## Review Focus

1. **A session expires on the status screen just as the final approval happens** — the next refresh turns the token into a member token, and the status screen must notice this and open the dashboard, not show "session expired". Test in Task 4 (`refresh` asks `/auth/me` first).
2. **No network while moving between steps** — the step must not advance and the typed values must stay. Test in Task 5 (`next()` with a `NetworkFailure`).
3. **Double tap on Submit, or a retry after a timeout** — the same idempotency key is sent. Test in Task 5.
4. **Nominee shares typed with Bangla digits or one decimal ("৩৩.৩")** — the running total reads them correctly. Test in Task 5 (`percentToHundredths`).
5. **An older backend without `account_type`** — login still opens the dashboard (defaults to member). Test in Task 2.

## File Structure

- Create `lib/modules/registration/`:
  - `model/registration_model.dart` (+ `.g.dart`): `RegistrationModel`, `TimelineStepModel`, `RegistrationDecisionModel`, `RegistrationDataModel`, `RegistrationNomineeModel`, `NomineeRelationModel`
  - `repository/registration_repository.dart`
  - `controller/registration_status_controller.dart`, `controller/registration_form_controller.dart`, `controller/nominee_form_row.dart`
  - `bindings/registration_binding.dart`
  - `view/registration_status_page.dart`, `view/registration_form_page.dart`, `view/widgets/registration_timeline.dart`, `view/widgets/nominee_card.dart`
- Create `lib/core/widgets/app_step_indicator.dart`, `lib/app/routes/home_route.dart`
- Modify: `lib/core/constants/api_constants.dart`, `lib/modules/authentication/model/auth_token_model.dart`, `lib/modules/authentication/login/controller/login_controller.dart`, `lib/modules/splash/repository/splash_repository.dart`, `lib/modules/splash/controller/splash_controller.dart`, `lib/app/routes/app_routes.dart`, `lib/app/routes/app_pages.dart`, `lib/app/localization/{translation_keys,en_us,bn_bd}.dart`, `docs/MEMBER_FEATURES.md`, `docs/MEMBER_API_SPECIFICATION.md`, `docs/MEMBER_NAVIGATION.md`
- Tests: `test/modules/registration_repository_test.dart`, `test/modules/registration_form_controller_test.dart`, `test/modules/registration_status_controller_test.dart`, `test/core/home_route_test.dart`, fixtures `test/fixtures/registration_invited.json`, `registration_submitted.json`, `nominee_relations.json`

---

### Task 1: API constants, models and repository

**Files:**
- Modify: `lib/core/constants/api_constants.dart`
- Create: `lib/modules/registration/model/registration_model.dart`, `lib/modules/registration/repository/registration_repository.dart`, the three fixtures
- Test: `test/modules/registration_repository_test.dart`

**Interfaces:**
- Produces:
  - `ApiConstants.registration = '/api/v1/registration'`, `registrationPhoto = '/api/v1/registration/photo'`, `registrationSubmit = '/api/v1/registration/submit'`, `nomineeRelations = '/api/v1/config/nominee-relations'`.
  - `IRegistrationRepository` methods: `getRegistration()`, `saveDraft(Map<String, dynamic> fields)`, `uploadPhoto(String path)`, `submit(String idempotencyKey)`, each returning `Future<({Failure? failure, RegistrationModel? registration})>`; `relations()` returning `Future<({Failure? failure, List<NomineeRelationModel> relations})>`; `accountType()` returning `Future<({Failure? failure, String? accountType})>` (from `/auth/me`).

- [ ] **Step 1: Fixtures** (exact backend shape, spec §8.4)

`test/fixtures/registration_invited.json`:

```json
{
  "success": true, "statusCode": 200, "message": "OK", "errors": null, "meta": null,
  "data": {
    "status": {"value": "invited", "label": "এখনও জমা হয়নি", "color": "gray"},
    "next_action": "complete",
    "can_edit": true,
    "headline": "আপনার নিবন্ধন অসম্পূর্ণ",
    "message": "চালিয়ে যেতে আপনার সদস্য নিবন্ধন সম্পূর্ণ করুন।",
    "timeline": [
      {"key": "submitted", "label": "তথ্য জমা", "state": "pending", "acted_at": null, "actor": null, "reason": null},
      {"key": "step_0", "label": "সম্পাদক-এর অনুমোদন", "state": "waiting", "acted_at": null, "actor": null, "reason": null},
      {"key": "step_1", "label": "সভাপতি-এর অনুমোদন", "state": "waiting", "acted_at": null, "actor": null, "reason": null},
      {"key": "activation", "label": "সদস্যপদ চালু", "state": "waiting", "acted_at": null, "actor": null, "reason": null}
    ],
    "decision": null,
    "data": {
      "name_bn": "করিম মিয়া", "name_en": null, "guardian_name": null, "nid": null, "date_of_birth": null,
      "mobile": "01811111111", "email": null, "address": null, "photo_url": null, "requested_shares": null,
      "nominees": []
    }
  }
}
```

`test/fixtures/registration_submitted.json`: the same with `"status": {"value": "submitted", "label": "অনুমোদনের অপেক্ষায়", "color": "info"}`, `"next_action": "wait"`, `"can_edit": false`, `"headline": "সম্পাদক-এর অনুমোদনের অপেক্ষায়"`, timeline states `done, pending, waiting, waiting`, `"acted_at": "2026-10-07T10:00:00+06:00"` on `submitted`, and one nominee `{"name": "করিমা", "relation_id": 3, "relation": "স্বামী/স্ত্রী", "mobile": null, "nid": "1234567890", "share_percent": "100.00"}`, `"requested_shares": 2`.

`test/fixtures/nominee_relations.json`:

```json
{"success": true, "statusCode": 200, "message": "OK", "errors": null, "meta": null,
 "data": [{"id": 1, "key": "father", "label": "পিতা"}, {"id": 3, "key": "spouse", "label": "স্বামী/স্ত্রী"}]}
```

- [ ] **Step 2: Write the failing test** — `test/modules/registration_repository_test.dart`

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/core/errors/failures.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/repository/registration_repository.dart';

import '../support/fake_api.dart';
import '../support/fixtures.dart';

void main() {
  test('reads the registration with server labels and timeline', () async {
    final api = FakeApi({'GET /api/v1/registration': (_) => (200, fixture('registration_submitted'))});

    final result = await RegistrationRepository(apiClient: api.client()).getRegistration();

    expect(result.failure, isNull);
    expect(result.registration!.status.value, 'submitted');
    expect(result.registration!.nextAction, 'wait');
    expect(result.registration!.timeline.map((s) => s.state), ['done', 'pending', 'waiting', 'waiting']);
    expect(result.registration!.data.nominees.single.relation, 'স্বামী/স্ত্রী');
  });

  test('saves only the fields it is given', () async {
    final api = FakeApi({'PUT /api/v1/registration': (_) => (200, fixture('registration_invited'))});

    await RegistrationRepository(apiClient: api.client()).saveDraft({'name_bn': 'করিম মিয়া'});

    expect(api.last.data, {'name_bn': 'করিম মিয়া'});
  });

  test('sends the idempotency key and maps a reused key to a conflict', () async {
    final api = FakeApi({'POST /api/v1/registration/submit': (_) => (409, envelopeError(409, 'already used'))});

    final result = await RegistrationRepository(apiClient: api.client()).submit('3f0c…');

    expect(api.last.data, {'idempotency_key': '3f0c…'});
    expect(result.failure, isA<ConflictFailure>());
  });

  test('shows field errors from a 422', () async {
    final api = FakeApi({
      'PUT /api/v1/registration': (_) => (422, envelopeError(422, 'invalid', errors: {'nid': ['NID must have 10, 13 or 17 digits.']})),
    });

    final result = await RegistrationRepository(apiClient: api.client()).saveDraft({'nid': '12'});

    expect(result.failure, isA<ValidationFailure>());
    expect(result.failure!.validationErrors!['nid']!.first, contains('NID'));
  });

  test('reads the relation list and the account type', () async {
    final api = FakeApi({
      'GET /api/v1/config/nominee-relations': (_) => (200, fixture('nominee_relations')),
      'GET /api/v1/auth/me': (_) => (200, envelope({'account_type': 'member', 'member_no': 'M-0007'})),
    });
    final repository = RegistrationRepository(apiClient: api.client());

    expect((await repository.relations()).relations.map((r) => r.label), ['পিতা', 'স্বামী/স্ত্রী']);
    expect((await repository.accountType()).accountType, 'member');
  });
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `flutter test test/modules/registration_repository_test.dart`
Expected: FAIL — `registration_repository.dart` not found.

- [ ] **Step 4: Models** — `lib/modules/registration/model/registration_model.dart`

```dart
import 'package:json_annotation/json_annotation.dart';

import '../../../core/models/enum_value_model.dart';

part 'registration_model.g.dart';

/// The member's own registration as `GET /api/v1/registration` returns it. Every label is already
/// in the member's language; the app shows them as they are.
@JsonSerializable(explicitToJson: true)
class RegistrationModel {
  final EnumValueModel status;
  @JsonKey(name: 'next_action')
  final String nextAction; // complete | resubmit | wait | none
  @JsonKey(name: 'can_edit')
  final bool canEdit;
  final String headline;
  final String message;
  final List<TimelineStepModel> timeline;
  final RegistrationDecisionModel? decision;
  final RegistrationDataModel data;

  const RegistrationModel({
    required this.status,
    required this.nextAction,
    required this.canEdit,
    required this.headline,
    required this.message,
    required this.timeline,
    required this.decision,
    required this.data,
  });

  factory RegistrationModel.fromJson(Map<String, dynamic> json) => _$RegistrationModelFromJson(json);

  Map<String, dynamic> toJson() => _$RegistrationModelToJson(this);
}

@JsonSerializable()
class TimelineStepModel {
  final String key;
  final String label;
  final String state; // done | pending | waiting | returned | rejected
  @JsonKey(name: 'acted_at')
  final String? actedAt;
  final String? actor;
  final String? reason;

  const TimelineStepModel({required this.key, required this.label, required this.state, this.actedAt, this.actor, this.reason});

  factory TimelineStepModel.fromJson(Map<String, dynamic> json) => _$TimelineStepModelFromJson(json);

  Map<String, dynamic> toJson() => _$TimelineStepModelToJson(this);
}

@JsonSerializable(explicitToJson: true)
class RegistrationDecisionModel {
  final EnumValueModel type;
  @JsonKey(name: 'by_role')
  final String byRole;
  final String? at;
  final String? reason;

  const RegistrationDecisionModel({required this.type, required this.byRole, this.at, this.reason});

  factory RegistrationDecisionModel.fromJson(Map<String, dynamic> json) => _$RegistrationDecisionModelFromJson(json);

  Map<String, dynamic> toJson() => _$RegistrationDecisionModelToJson(this);
}

@JsonSerializable(explicitToJson: true)
class RegistrationDataModel {
  @JsonKey(name: 'name_bn')
  final String? nameBn;
  @JsonKey(name: 'name_en')
  final String? nameEn;
  @JsonKey(name: 'guardian_name')
  final String? guardianName;
  final String? nid;
  @JsonKey(name: 'date_of_birth')
  final String? dateOfBirth;
  final String mobile;
  final String? email;
  final String? address;
  @JsonKey(name: 'photo_url')
  final String? photoUrl;
  @JsonKey(name: 'requested_shares')
  final int? requestedShares;
  final List<RegistrationNomineeModel> nominees;

  const RegistrationDataModel({
    this.nameBn,
    this.nameEn,
    this.guardianName,
    this.nid,
    this.dateOfBirth,
    required this.mobile,
    this.email,
    this.address,
    this.photoUrl,
    this.requestedShares,
    required this.nominees,
  });

  factory RegistrationDataModel.fromJson(Map<String, dynamic> json) => _$RegistrationDataModelFromJson(json);

  Map<String, dynamic> toJson() => _$RegistrationDataModelToJson(this);
}

@JsonSerializable()
class RegistrationNomineeModel {
  final String name;
  @JsonKey(name: 'relation_id')
  final int? relationId;
  final String? relation;
  final String? mobile;
  final String? nid;
  @JsonKey(name: 'share_percent')
  final String sharePercent;

  const RegistrationNomineeModel({required this.name, this.relationId, this.relation, this.mobile, this.nid, required this.sharePercent});

  factory RegistrationNomineeModel.fromJson(Map<String, dynamic> json) => _$RegistrationNomineeModelFromJson(json);

  Map<String, dynamic> toJson() => _$RegistrationNomineeModelToJson(this);
}

@JsonSerializable()
class NomineeRelationModel {
  final int id;
  final String key;
  final String label;

  const NomineeRelationModel({required this.id, required this.key, required this.label});

  factory NomineeRelationModel.fromJson(Map<String, dynamic> json) => _$NomineeRelationModelFromJson(json);

  Map<String, dynamic> toJson() => _$NomineeRelationModelToJson(this);
}
```

Run: `dart run build_runner build --delete-conflicting-outputs`

- [ ] **Step 5: Constants and repository**

`ApiConstants` (after `somitiInfo`):

```dart
  static const String nomineeRelations = '/api/v1/config/nominee-relations';

  // Self-registration (applicant token)
  static const String registration = '/api/v1/registration';
  static const String registrationPhoto = '/api/v1/registration/photo';
  static const String registrationSubmit = '/api/v1/registration/submit';
```

`lib/modules/registration/repository/registration_repository.dart`:

```dart
import 'package:dio/dio.dart';

import '../../../core/constants/api_constants.dart';
import '../../../core/errors/error_handler.dart';
import '../../../core/errors/failures.dart';
import '../../../core/network/api_client.dart';
import '../model/registration_model.dart';

typedef RegistrationResult = ({Failure? failure, RegistrationModel? registration});

abstract class IRegistrationRepository {
  Future<RegistrationResult> getRegistration();

  /// Saves the given fields only (a partial draft); `nominees` replaces the whole list.
  Future<RegistrationResult> saveDraft(Map<String, dynamic> fields);

  Future<RegistrationResult> uploadPhoto(String path);

  /// One key per form: a retry with the same key never submits twice.
  Future<RegistrationResult> submit(String idempotencyKey);

  Future<({Failure? failure, List<NomineeRelationModel> relations})> relations();

  /// "member" or "applicant" — asked before showing the status, so an approval is noticed.
  Future<({Failure? failure, String? accountType})> accountType();
}

class RegistrationRepository implements IRegistrationRepository {
  final ApiClient apiClient;

  RegistrationRepository({required this.apiClient});

  @override
  Future<RegistrationResult> getRegistration() => _registration(() => apiClient.get(ApiConstants.registration));

  @override
  Future<RegistrationResult> saveDraft(Map<String, dynamic> fields) =>
      _registration(() => apiClient.put(ApiConstants.registration, data: fields));

  @override
  Future<RegistrationResult> uploadPhoto(String path) async => _registration(
        () async => apiClient.post(ApiConstants.registrationPhoto, data: FormData.fromMap({'photo': await MultipartFile.fromFile(path)})),
      );

  @override
  Future<RegistrationResult> submit(String idempotencyKey) =>
      _registration(() => apiClient.post(ApiConstants.registrationSubmit, data: {'idempotency_key': idempotencyKey}));

  @override
  Future<({Failure? failure, List<NomineeRelationModel> relations})> relations() async {
    try {
      final response = await apiClient.get(ApiConstants.nomineeRelations);
      final rows = (response.data as Map<String, dynamic>)['data'] as List<dynamic>;
      return (failure: null, relations: rows.map((row) => NomineeRelationModel.fromJson(row as Map<String, dynamic>)).toList());
    } catch (e) {
      return (failure: ErrorHandler.handleException(e), relations: <NomineeRelationModel>[]);
    }
  }

  @override
  Future<({Failure? failure, String? accountType})> accountType() async {
    try {
      final response = await apiClient.get(ApiConstants.me);
      final data = (response.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
      return (failure: null, accountType: (data['account_type'] as String?) ?? 'member');
    } catch (e) {
      return (failure: ErrorHandler.handleException(e), accountType: null);
    }
  }

  Future<RegistrationResult> _registration(Future<Response<dynamic>> Function() call) async {
    try {
      final response = await call();
      final data = (response.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
      return (failure: null, registration: RegistrationModel.fromJson(data));
    } catch (e) {
      return (failure: ErrorHandler.handleException(e), registration: null);
    }
  }
}
```
(Check `ApiClient.put`/`post` signatures in `lib/core/network/api_client.dart` — they take `data:` like Dio; match them if the parameter is named differently.)

- [ ] **Step 6: Run the tests**

Run: `flutter test test/modules/registration_repository_test.dart && flutter analyze`
Expected: PASS, no issues.

- [ ] **Step 7: Commit**

```bash
git add lib/core/constants/api_constants.dart lib/modules/registration test/modules/registration_repository_test.dart test/fixtures/registration_invited.json test/fixtures/registration_submitted.json test/fixtures/nominee_relations.json
git commit -m "Registration: API models and repository"
```

---

### Task 2: Choose the first screen from the account type

**Files:**
- Create: `lib/app/routes/home_route.dart`
- Modify: `lib/modules/authentication/model/auth_token_model.dart`, `lib/modules/authentication/login/controller/login_controller.dart`, `lib/modules/splash/repository/splash_repository.dart`, `lib/modules/splash/controller/splash_controller.dart`, `lib/app/routes/app_routes.dart`
- Test: `test/core/home_route_test.dart`, extend `test/modules/auth_repository_test.dart`

**Interfaces:**
- Produces: `AuthTokenModel.accountType` (default `'member'`); `String homeRouteFor(String? accountType)`; `AppRoutes.registration = '/registration'`, `AppRoutes.registrationForm = '/registration/form'`; `ISplashRepository.me()` returns `({Failure? failure, bool isValid, String? accountType})`.

- [ ] **Step 1: Write the failing tests**

`test/core/home_route_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/app/routes/app_routes.dart';
import 'package:somobay_somiti_mobile_app/app/routes/home_route.dart';

void main() {
  test('someone still registering starts on the registration status', () {
    expect(homeRouteFor('applicant'), AppRoutes.registration);
  });

  test('members, and older backends that send no account type, start on the dashboard', () {
    expect(homeRouteFor('member'), AppRoutes.dashboard);
    expect(homeRouteFor(null), AppRoutes.dashboard);
  });
}
```

In `test/modules/auth_repository_test.dart` add:

```dart
  test('reads the account type from the login answer, member when missing', () async {
    final api = FakeApi({
      'POST /api/v1/auth/login': (_) => (200, envelope({'access_token': 'a', 'refresh_token': 'r', 'token_type': 'Bearer', 'expires_in': 3600, 'account_type': 'applicant'})),
    });
    final applicant = await AuthRepository(apiClient: api.client()).login(const LoginRequestModel(mobile: '01811111111', password: 'secret-123'));

    expect(applicant.tokens!.accountType, 'applicant');
    expect(AuthTokenModel.fromJson({'access_token': 'a', 'refresh_token': 'r'}).accountType, 'member');
  });
```
(Use the constructor and imports the file's existing login test uses.)

- [ ] **Step 2: Run to verify they fail**

Run: `flutter test test/core/home_route_test.dart test/modules/auth_repository_test.dart`
Expected: FAIL — `home_route.dart` not found / no `accountType`.

- [ ] **Step 3: Implement**

`AuthTokenModel` — add:

```dart
  /// "member" or "applicant" (someone still registering). Older backends send nothing: member.
  @JsonKey(name: 'account_type', defaultValue: 'member')
  final String accountType;
```
with `this.accountType = 'member'` in the constructor; re-run `build_runner`.

`AppRoutes` — add next to `dashboard`:

```dart
  // Self-registration (docs/MEMBER_NAVIGATION.md)
  static const String registration = '/registration';
  static const String registrationForm = '/registration/form';
```
and delete the old hidden `register` constant only if nothing references it (`grep -rn "AppRoutes.register\b" lib`); otherwise leave it.

`lib/app/routes/home_route.dart`:

```dart
import 'app_routes.dart';

/// The first screen after sign-in or start-up: people still registering see their registration,
/// members the dashboard.
String homeRouteFor(String? accountType) => accountType == 'applicant' ? AppRoutes.registration : AppRoutes.dashboard;
```

`LoginController.login()` — replace `Get.offAllNamed(AppRoutes.dashboard);` with `Get.offAllNamed(homeRouteFor(result.tokens!.accountType));` (import `home_route.dart`; drop the `app_routes.dart` import if unused).

`SplashRepository.me()`:

```dart
  /// Whether the stored session is still accepted, and whose it is (member or applicant).
  @override
  Future<({Failure? failure, bool isValid, String? accountType})> me() async {
    try {
      final response = await apiClient.get(ApiConstants.me);
      final data = (response.data as Map<String, dynamic>)['data'] as Map<String, dynamic>;
      return (failure: null, isValid: true, accountType: (data['account_type'] as String?) ?? 'member');
    } catch (e) {
      return (failure: ErrorHandler.handleException(e), isValid: false, accountType: null);
    }
  }
```
(and the same signature in `ISplashRepository`).

`SplashController` — the accepted branch becomes:

```dart
      if (session.isValid || session.failure is NetworkFailure || session.failure is TimeoutFailure) {
        // Offline with a session: open the app (as a member — the registration screens need the network anyway).
        LogService.i('Session accepted (or offline), opening ${session.accountType ?? 'member'} home', tag: 'STARTUP');
        Get.offAllNamed(homeRouteFor(session.accountType));
      }
```

- [ ] **Step 4: Run the tests**

Run: `flutter test && flutter analyze`
Expected: PASS (fix any test fake that implements `ISplashRepository` to the new `me()` signature).

- [ ] **Step 5: Commit**

```bash
git add lib/app/routes lib/modules/authentication lib/modules/splash test/core/home_route_test.dart test/modules/auth_repository_test.dart
git commit -m "Start-up and login open the registration for people still registering"
```

---

### Task 3: Translations and the step indicator widget

**Files:**
- Modify: `lib/app/localization/translation_keys.dart`, `en_us.dart`, `bn_bd.dart`
- Create: `lib/core/widgets/app_step_indicator.dart`
- Test: `test/widgets/app_step_indicator_test.dart`

**Interfaces:**
- Produces: the `registration_*` keys below; `AppStepIndicator({required List<String> labels, required int current, ValueChanged<int>? onTap})`.

- [ ] **Step 1: Keys** — add to `TranslationKeys` (constants named in camelCase as the file does) and both maps:

| key | en | bn |
|---|---|---|
| `registration_title` | Member registration | সদস্য নিবন্ধন |
| `registration_status_title` | Registration status | নিবন্ধনের অবস্থা |
| `registration_step_personal` | Personal | ব্যক্তিগত |
| `registration_step_contact` | Contact | যোগাযোগ |
| `registration_step_nominees` | Nominees | নমিনি |
| `registration_step_shares` | Shares | শেয়ার |
| `registration_step_review` | Review | যাচাই |
| `registration_name_bn` | Name (Bangla) | নাম (বাংলা) |
| `registration_name_en` | Name (English) | নাম (ইংরেজি) |
| `registration_guardian` | Father's / husband's name | পিতা/স্বামীর নাম |
| `registration_nid` | NID | জাতীয় পরিচয়পত্র নং |
| `registration_dob` | Date of birth | জন্ম তারিখ |
| `registration_photo` | Photo | ছবি |
| `registration_photo_pick` | Choose photo | ছবি বাছুন |
| `registration_mobile` | Mobile (your login) | মোবাইল (আপনার লগইন) |
| `registration_email` | Email | ইমেইল |
| `registration_address` | Address | ঠিকানা |
| `registration_nominee` | Nominee | নমিনি |
| `registration_nominee_name` | Nominee's name | নমিনির নাম |
| `registration_logout` | Sign out | লগআউট |
| `registration_nominee_add` | Add nominee | নমিনি যোগ করুন |
| `registration_nominee_remove` | Remove | বাদ দিন |
| `registration_nominee_relation` | Relation | সম্পর্ক |
| `registration_nominee_share` | Share (%) | অংশ (%) |
| `registration_nominee_total` | Total: @total% (must be 100%) | মোট: @total% (১০০% হতে হবে) |
| `registration_shares_requested` | How many shares do you want? | কত শেয়ার চান? |
| `registration_shares_help` | The committee confirms the number and the start month. | কমিটি শেয়ারের সংখ্যা ও শুরুর মাস নিশ্চিত করবে। |
| `registration_next` | Save and continue | সংরক্ষণ করে এগিয়ে যান |
| `registration_back` | Back | পেছনে |
| `registration_edit` | Edit | সম্পাদনা |
| `registration_submit` | Submit registration | নিবন্ধন জমা দিন |
| `registration_submit_confirm_title` | Submit your registration? | নিবন্ধন জমা দেবেন? |
| `registration_submit_confirm_message` | After submitting you cannot change it unless it is sent back to you. | জমার পর ফেরত না আসা পর্যন্ত আর বদলাতে পারবেন না। |
| `registration_submitted` | Registration submitted | নিবন্ধন জমা হয়েছে |
| `registration_action_complete` | Complete registration | নিবন্ধন সম্পূর্ণ করুন |
| `registration_action_resubmit` | Correct and submit again | সংশোধন করে আবার জমা দিন |
| `registration_reason` | Reason | কারণ |
| `registration_required` | Required | আবশ্যক |
| `registration_nominee_needed` | Add at least one nominee | অন্তত একজন নমিনি যোগ করুন |
| `registration_total_not_100` | Nominee shares must add up to 100% | নমিনিদের অংশ মিলে ১০০% হতে হবে |

- [ ] **Step 2: Write the failing widget test** — `test/widgets/app_step_indicator_test.dart`

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/core/widgets/app_step_indicator.dart';

void main() {
  testWidgets('shows every step, ticks the done ones and reports taps on them', (tester) async {
    int? tapped;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: AppStepIndicator(labels: const ['A', 'B', 'C'], current: 1, onTap: (i) => tapped = i)),
    ));

    expect(find.text('A'), findsOneWidget);
    expect(find.byIcon(Icons.check), findsOneWidget); // step A is done
    expect(find.text('3'), findsOneWidget); // step C still numbered

    await tester.tap(find.text('A'));
    expect(tapped, 0);

    await tester.tap(find.text('C'));
    expect(tapped, 0); // future steps are not tappable
  });
}
```

- [ ] **Step 3: Implement** — `lib/core/widgets/app_step_indicator.dart`

```dart
import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_text_styles.dart';
import '../utils/bangla_number_util.dart';

/// "① Personal — ② Contact — …": done steps show a tick and can be tapped to go back; the current
/// step is filled; later steps are greyed out.
class AppStepIndicator extends StatelessWidget {
  final List<String> labels;
  final int current;
  final ValueChanged<int>? onTap;

  const AppStepIndicator({super.key, required this.labels, required this.current, this.onTap});

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          for (var i = 0; i < labels.length; i++) ...[
            if (i > 0) Container(width: 16, height: 2, color: i <= current ? AppColors.primary : AppColors.textSecondary.withValues(alpha: 0.3)),
            InkWell(
              onTap: i < current && onTap != null ? () => onTap!(i) : null,
              borderRadius: BorderRadius.circular(16),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 12,
                      backgroundColor: i <= current ? AppColors.primary : AppColors.textSecondary.withValues(alpha: 0.2),
                      child: i < current
                          ? const Icon(Icons.check, size: 14, color: Colors.white)
                          : Text(BanglaNumberUtil.localize('${i + 1}'), style: AppTextStyles.caption.copyWith(color: i == current ? Colors.white : AppColors.textSecondary)),
                    ),
                    const SizedBox(width: 6),
                    Text(labels[i], style: AppTextStyles.bodySmall.copyWith(fontWeight: i == current ? FontWeight.w600 : FontWeight.normal, color: i <= current ? null : AppColors.textSecondary)),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
```
(Use whatever `BanglaNumberUtil` exposes for English → locale digits — check `lib/core/utils/bangla_number_util.dart`; if it only has `toEnglish`/`toBangla`, call `Get.locale?.languageCode == 'bn' ? BanglaNumberUtil.toBangla(...) : ...`. In the test, English digits are expected because no GetX locale is set.)

- [ ] **Step 4: Run the tests**

Run: `flutter test test/widgets/app_step_indicator_test.dart && flutter analyze`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add lib/app/localization lib/core/widgets/app_step_indicator.dart test/widgets/app_step_indicator_test.dart
git commit -m "Registration: translations and step indicator"
```

---

### Task 4: Registration status screen

**Files:**
- Create: `lib/modules/registration/controller/registration_status_controller.dart`, `lib/modules/registration/bindings/registration_binding.dart`, `lib/modules/registration/view/registration_status_page.dart`, `lib/modules/registration/view/widgets/registration_timeline.dart`
- Modify: `lib/app/routes/app_pages.dart`
- Test: `test/modules/registration_status_controller_test.dart`

**Interfaces:**
- Consumes: `IRegistrationRepository` (Task 1), `homeRouteFor` (Task 2), `ProfileRepository.logout` for sign-out (existing).
- Produces: `RegistrationStatusController.refresh(): Future<String?>` — returns the route to leave for (`AppRoutes.dashboard` once approved) or null to stay; `RegistrationBinding` (registers the repository and both controllers lazily).

- [ ] **Step 1: Write the failing test** — `test/modules/registration_status_controller_test.dart`

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/app/routes/app_routes.dart';
import 'package:somobay_somiti_mobile_app/core/errors/failures.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/controller/registration_status_controller.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/model/registration_model.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/repository/registration_repository.dart';

import '../support/fixtures.dart';

class FakeRegistrationRepository implements IRegistrationRepository {
  String? account = 'applicant';
  Failure? accountFailure;
  int registrationCalls = 0;

  @override
  Future<({Failure? failure, String? accountType})> accountType() async => (failure: accountFailure, accountType: accountFailure == null ? account : null);

  @override
  Future<RegistrationResult> getRegistration() async {
    registrationCalls++;
    return (failure: null, registration: RegistrationModel.fromJson(fixture('registration_submitted')['data'] as Map<String, dynamic>));
  }

  @override
  noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

void main() {
  test('shows the registration while the person is still registering', () async {
    final repository = FakeRegistrationRepository();
    final controller = RegistrationStatusController(repository: repository);

    expect(await controller.refresh(), isNull);
    expect(controller.state.value.data!.headline, contains('অনুমোদনের অপেক্ষায়'));
  });

  test('leaves for the dashboard as soon as the account became a member', () async {
    final repository = FakeRegistrationRepository()..account = 'member';
    final controller = RegistrationStatusController(repository: repository);

    expect(await controller.refresh(), AppRoutes.dashboard);
    expect(repository.registrationCalls, 0);
  });

  test('shows an error state, not a sign-out, when offline', () async {
    final repository = FakeRegistrationRepository()..accountFailure = const NetworkFailure();
    final controller = RegistrationStatusController(repository: repository);

    expect(await controller.refresh(), isNull);
    expect(controller.state.value.isError, isTrue);
  });
}
```
(Use the `NetworkFailure` constructor as `failures.dart` defines it.)

- [ ] **Step 2: Run to verify it fails**

Run: `flutter test test/modules/registration_status_controller_test.dart`
Expected: FAIL — controller not found.

- [ ] **Step 3: Controller** — `registration_status_controller.dart`

```dart
import 'package:get/get.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/routes/home_route.dart';
import '../../../core/errors/failures.dart';
import '../../../core/models/ui_state.dart';
import '../../../core/services/storage_service.dart';
import '../../profile_settings/repository/profile_repository.dart';
import '../model/registration_model.dart';
import '../repository/registration_repository.dart';

/// "Where is my registration?" Asks whose session this is first: right after the final approval
/// the session has become a member's, and the member belongs on the dashboard.
class RegistrationStatusController extends GetxController {
  final IRegistrationRepository repository;
  final IProfileRepository? profileRepository;
  final StorageService? storageService;

  RegistrationStatusController({required this.repository, this.profileRepository, this.storageService});

  final state = UIState<RegistrationModel>.initial().obs;

  /// Same as the profile page's sign-out: tell the server (best effort), forget the tokens.
  Future<void> logout() async {
    await profileRepository?.logout();
    await storageService?.clearAuthData();
    Get.offAllNamed(AppRoutes.login);
  }

  /// The route to leave for, or null to stay on this screen.
  Future<String?> refresh() async {
    state.value = UIState.loading();

    final account = await repository.accountType();
    if (account.failure != null) {
      state.value = UIState.error(account.failure!);
      return null;
    }

    if (account.accountType != 'applicant') {
      return homeRouteFor(account.accountType);
    }

    final result = await repository.getRegistration();
    state.value = result.registration != null ? UIState.success(result.registration!) : UIState.error(result.failure ?? const UnknownFailure());
    return null;
  }
}
```

- [ ] **Step 4: Binding, timeline widget, page, route**

`registration_binding.dart`:

```dart
import 'package:get/get.dart';

import '../../../core/network/api_client.dart';
import '../../../core/services/storage_service.dart';
import '../../profile_settings/repository/profile_repository.dart';
import '../controller/registration_form_controller.dart';
import '../controller/registration_status_controller.dart';
import '../repository/registration_repository.dart';

class RegistrationStatusBinding extends Bindings {
  @override
  void dependencies() {
    Get.lazyPut<IRegistrationRepository>(() => RegistrationRepository(apiClient: Get.find<ApiClient>()), fenix: true);
    Get.lazyPut<IProfileRepository>(() => ProfileRepository(apiClient: Get.find<ApiClient>()), fenix: true);
    Get.lazyPut<RegistrationStatusController>(() => RegistrationStatusController(
          repository: Get.find<IRegistrationRepository>(),
          profileRepository: Get.find<IProfileRepository>(),
          storageService: Get.find<StorageService>(),
        ));
  }
}

class RegistrationFormBinding extends Bindings {
  @override
  void dependencies() {
    Get.lazyPut<IRegistrationRepository>(() => RegistrationRepository(apiClient: Get.find<ApiClient>()), fenix: true);
    Get.lazyPut<RegistrationFormController>(() => RegistrationFormController(repository: Get.find<IRegistrationRepository>()));
  }
}
```
(Match `IProfileRepository`/`ProfileRepository` names to `lib/modules/profile_settings/repository/`. `RegistrationFormController` arrives in Task 5 — create an empty `class RegistrationFormController extends GetxController { RegistrationFormController({required IRegistrationRepository repository}); }` now so the binding compiles, and replace it in Task 5.)

`view/widgets/registration_timeline.dart`:

```dart
import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../app/theme/app_text_styles.dart';
import '../../../../core/utils/api_date_format.dart';
import '../../model/registration_model.dart';

/// ✓ done · ● pending · ○ waiting · ↩ returned · ✕ rejected — labels straight from the server.
class RegistrationTimeline extends StatelessWidget {
  final List<TimelineStepModel> steps;

  const RegistrationTimeline({super.key, required this.steps});

  static (IconData, Color) _look(String state) => switch (state) {
        'done' => (Icons.check_circle, AppColors.success),
        'pending' => (Icons.radio_button_checked, AppColors.pending),
        'returned' => (Icons.undo, AppColors.pending),
        'rejected' => (Icons.cancel, AppColors.error),
        _ => (Icons.radio_button_unchecked, AppColors.textSecondary),
      };

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < steps.length; i++)
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Column(children: [
                  Icon(_look(steps[i].state).$1, color: _look(steps[i].state).$2),
                  if (i < steps.length - 1) Expanded(child: Container(width: 2, color: AppColors.textSecondary.withValues(alpha: 0.3))),
                ]),
                const SizedBox(width: 12),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 20),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(steps[i].label, style: AppTextStyles.titleMedium),
                      if (steps[i].actedAt != null)
                        Text(
                          [ApiDateFormat.dateTime(steps[i].actedAt!), if (steps[i].actor != null) steps[i].actor!].join(' · '),
                          style: AppTextStyles.bodySmall.copyWith(color: AppColors.textSecondary),
                        ),
                      if (steps[i].reason != null) Text(steps[i].reason!, style: AppTextStyles.bodyMedium),
                    ]),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
```
(Use the date-time formatter `ApiDateFormat` already offers; if it has only `date()`, show `ApiDateFormat.date(steps[i].actedAt!.substring(0, 10))`.)

`view/registration_status_page.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_text_styles.dart';
import '../../../core/widgets/app_app_bar.dart';
import '../../../core/widgets/app_buttons.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_error_state.dart';
import '../../../core/widgets/app_loading.dart';
import '../../../core/widgets/app_status_chip.dart';
import '../controller/registration_status_controller.dart';
import 'widgets/registration_timeline.dart';

class RegistrationStatusPage extends StatefulWidget {
  const RegistrationStatusPage({super.key});

  @override
  State<RegistrationStatusPage> createState() => _RegistrationStatusPageState();
}

class _RegistrationStatusPageState extends State<RegistrationStatusPage> {
  final controller = Get.find<RegistrationStatusController>();

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final leaveFor = await controller.refresh();
    if (leaveFor != null) Get.offAllNamed(leaveFor);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppAppBar(
        title: 'registration_status_title'.tr,
        actions: [IconButton(icon: const Icon(Icons.logout), tooltip: 'registration_logout'.tr, onPressed: controller.logout)],
      ),
      body: Obx(() {
        final state = controller.state.value;
        if (state.isLoading || state.isInitial) return const AppLoading();
        if (state.isError || state.data == null) return AppErrorState(failure: state.failure, onRetry: _refresh);
        final registration = state.data!;

        return RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            AppCard(
              margin: EdgeInsets.zero,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(registration.headline, style: AppTextStyles.h3)),
                  AppStatusChip(status: registration.status),
                ]),
                const SizedBox(height: 8),
                Text(registration.message, style: AppTextStyles.bodyMedium),
                if (registration.decision?.reason != null) ...[
                  const SizedBox(height: 12),
                  Text('${'registration_reason'.tr} (${registration.decision!.byRole}): ${registration.decision!.reason}', style: AppTextStyles.bodyMedium.copyWith(color: AppColors.error)),
                ],
              ]),
            ),
            const SizedBox(height: 16),
            AppCard(margin: EdgeInsets.zero, child: RegistrationTimeline(steps: registration.timeline)),
            if (registration.canEdit) ...[
              const SizedBox(height: 24),
              AppButton(
                text: registration.nextAction == 'resubmit' ? 'registration_action_resubmit'.tr : 'registration_action_complete'.tr,
                onPressed: () async {
                  await Get.toNamed(AppRoutes.registrationForm);
                  _refresh();
                },
              ),
            ],
          ]),
        );
      }),
    );
  }
}
```
(Remove the `profile_controller.dart` import. Check that `AppAppBar` accepts `actions`; if it does not, add an optional `List<Widget>? actions` passed to its `AppBar`. Match the `IProfileRepository`/`ProfileRepository` names and `logout()` signature in `lib/modules/profile_settings/repository/`.)

`app_pages.dart` — add:

```dart
    GetPage(name: AppRoutes.registration, page: () => const RegistrationStatusPage(), binding: RegistrationStatusBinding()),
    GetPage(name: AppRoutes.registrationForm, page: () => const RegistrationFormPage(), binding: RegistrationFormBinding()),
```
(The form page arrives in Task 6; add its `GetPage` line in Task 6.)

- [ ] **Step 5: Run the tests**

Run: `flutter test && flutter analyze`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/modules/registration lib/app/routes/app_pages.dart test/modules/registration_status_controller_test.dart
git commit -m "Registration: status screen with server timeline"
```

---

### Task 5: Registration form controller (steps, draft saving, nominees, submit)

**Files:**
- Create: `lib/modules/registration/controller/nominee_form_row.dart`
- Replace: `lib/modules/registration/controller/registration_form_controller.dart`
- Test: `test/modules/registration_form_controller_test.dart`

**Interfaces:**
- Consumes: `IRegistrationRepository` (Task 1).
- Produces: `RegistrationFormController` with `stepLabels` (5 keys), `currentStep` (RxInt), `formKeys` (4 `GlobalKey<FormState>`, one per input step), `load()`, `next(): Future<bool>` (validates + saves the current step; advances on success), `back()`, `goTo(int)`, `addNominee()`, `removeNominee(int)`, `nomineeTotalHundredths` (int getter), `submit(): Future<bool>`, `attachPhoto(String path): Future<String?>`, `errorFor(String field)`; `NomineeFormRow` with `toJson()`; top-level `int? percentToHundredths(String text)`.

- [ ] **Step 1: Write the failing test** — `test/modules/registration_form_controller_test.dart`

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:somobay_somiti_mobile_app/core/errors/failures.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/controller/registration_form_controller.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/model/registration_model.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/repository/registration_repository.dart';

import '../support/fixtures.dart';

RegistrationModel invited() => RegistrationModel.fromJson(fixture('registration_invited')['data'] as Map<String, dynamic>);

class RecordingRegistrationRepository implements IRegistrationRepository {
  final List<Map<String, dynamic>> saved = [];
  final List<String> submitKeys = [];
  final List<Failure?> answers = [];

  @override
  Future<RegistrationResult> getRegistration() async => (failure: null, registration: invited());

  @override
  Future<({Failure? failure, List<NomineeRelationModel> relations})> relations() async =>
      (failure: null, relations: const [NomineeRelationModel(id: 3, key: 'spouse', label: 'স্বামী/স্ত্রী')]);

  @override
  Future<RegistrationResult> saveDraft(Map<String, dynamic> fields) async {
    saved.add(fields);
    final failure = answers.isEmpty ? null : answers.removeAt(0);
    return (failure: failure, registration: failure == null ? invited() : null);
  }

  @override
  Future<RegistrationResult> submit(String idempotencyKey) async {
    submitKeys.add(idempotencyKey);
    final failure = answers.isEmpty ? null : answers.removeAt(0);
    return (failure: failure, registration: failure == null ? invited() : null);
  }

  @override
  noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('starts from the saved draft', () async {
    final controller = RegistrationFormController(repository: RecordingRegistrationRepository());
    await controller.load();

    expect(controller.nameBnController.text, 'করিম মিয়া');
    expect(controller.mobile.value, '01811111111');
    expect(controller.relations.single.label, 'স্বামী/স্ত্রী');
    expect(controller.nominees, hasLength(1)); // one empty row to start with
  });

  test('saves only the current step and moves on', () async {
    final repository = RecordingRegistrationRepository();
    final controller = RegistrationFormController(repository: repository)..skipValidationForTests = true;
    await controller.load();
    controller.nameEnController.text = 'Karim Mia';

    expect(await controller.next(), isTrue);
    expect(repository.saved.single.keys, containsAll(['name_bn', 'name_en', 'guardian_name', 'nid', 'date_of_birth']));
    expect(repository.saved.single['name_en'], 'Karim Mia');
    expect(controller.currentStep.value, 1);
  });

  test('stays on the step with the typed values when the network is down', () async {
    final repository = RecordingRegistrationRepository()..answers.add(const NetworkFailure());
    final controller = RegistrationFormController(repository: repository)..skipValidationForTests = true;
    await controller.load();
    controller.nameEnController.text = 'Karim Mia';

    expect(await controller.next(), isFalse);
    expect(controller.currentStep.value, 0);
    expect(controller.nameEnController.text, 'Karim Mia');
    expect(controller.failure.value, isA<NetworkFailure>());
  });

  test('sends nominees as the API expects', () async {
    final repository = RecordingRegistrationRepository();
    final controller = RegistrationFormController(repository: repository)..skipValidationForTests = true;
    await controller.load();
    controller.currentStep.value = 2;
    controller.nominees.first
      ..nameController.text = 'করিমা'
      ..relationId.value = 3
      ..nidController.text = '১২৩৪৫৬৭৮৯০'
      ..shareController.text = '১০০';

    await controller.next();

    expect(repository.saved.single, {
      'nominees': [
        {'name': 'করিমা', 'relation_id': 3, 'nid': '1234567890', 'mobile': null, 'share_percent': '100'},
      ],
    });
  });

  test('reuses the submit key after a failure and renews it after success', () async {
    final repository = RecordingRegistrationRepository()..answers.addAll([const TimeoutFailure(), null, null]);
    final controller = RegistrationFormController(repository: repository);
    await controller.load();

    expect(await controller.submit(), isFalse);
    expect(await controller.submit(), isTrue);
    expect(await controller.submit(), isTrue);

    expect(repository.submitKeys[0], repository.submitKeys[1]);
    expect(repository.submitKeys[2], isNot(repository.submitKeys[1]));
  });

  test('adds up nominee shares in hundredths without floating point', () {
    expect(percentToHundredths('100'), 10000);
    expect(percentToHundredths('৩৩.৩'), 3330);
    expect(percentToHundredths('66.67'), 6667);
    expect(percentToHundredths('12.345'), isNull);
    expect(percentToHundredths('abc'), isNull);
  });
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `flutter test test/modules/registration_form_controller_test.dart`
Expected: FAIL — members not found.

- [ ] **Step 3: Nominee row** — `nominee_form_row.dart`

```dart
import 'package:flutter/widgets.dart';
import 'package:get/get.dart';

import '../../../core/utils/bangla_number_util.dart';
import '../model/registration_model.dart';

/// One nominee card on the form; its text fields and chosen relation.
class NomineeFormRow {
  final nameController = TextEditingController();
  final nidController = TextEditingController();
  final mobileController = TextEditingController();
  final shareController = TextEditingController();
  final relationId = RxnInt();

  NomineeFormRow();

  factory NomineeFormRow.fromModel(RegistrationNomineeModel nominee) {
    final row = NomineeFormRow();
    row.nameController.text = nominee.name;
    row.nidController.text = nominee.nid ?? '';
    row.mobileController.text = nominee.mobile ?? '';
    row.shareController.text = nominee.sharePercent;
    row.relationId.value = nominee.relationId;
    return row;
  }

  Map<String, dynamic> toJson() {
    String? text(TextEditingController controller) {
      final value = BanglaNumberUtil.toEnglish(controller.text.trim());
      return value.isEmpty ? null : value;
    }

    return {
      'name': nameController.text.trim(),
      'relation_id': relationId.value,
      'nid': text(nidController),
      'mobile': text(mobileController),
      'share_percent': text(shareController),
    };
  }

  void dispose() {
    nameController.dispose();
    nidController.dispose();
    mobileController.dispose();
    shareController.dispose();
  }
}
```

- [ ] **Step 4: Controller** — replace `registration_form_controller.dart`

```dart
import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
import 'package:uuid/uuid.dart';

import '../../../core/errors/failures.dart';
import '../../../core/models/ui_state.dart';
import '../../../core/utils/bangla_number_util.dart';
import '../../../core/utils/image_compressor.dart';
import '../model/registration_model.dart';
import '../repository/registration_repository.dart';
import 'nominee_form_row.dart';

/// "33.3" → 3330 hundredths of a percent (100% = 10000). Null when it is not a percentage with at
/// most two decimals. Integers only: the app never does floating-point arithmetic on shares.
int? percentToHundredths(String text) {
  final value = BanglaNumberUtil.toEnglish(text.trim());
  final match = RegExp(r'^(\d{1,3})(?:\.(\d{1,2}))?$').firstMatch(value);
  if (match == null) return null;
  return int.parse(match.group(1)!) * 100 + int.parse((match.group(2) ?? '0').padRight(2, '0'));
}

/// The member's own registration in five steps. Each "next" saves that step's fields as a draft on
/// the server, so nothing is lost if the app closes. Submit uses one idempotency key per form.
class RegistrationFormController extends GetxController {
  final IRegistrationRepository repository;

  RegistrationFormController({required this.repository});

  static const stepLabels = [
    'registration_step_personal',
    'registration_step_contact',
    'registration_step_nominees',
    'registration_step_shares',
    'registration_step_review',
  ];

  /// Lets unit tests drive steps without a widget tree (the Form widgets validate in the app).
  @visibleForTesting
  bool skipValidationForTests = false;

  final formKeys = List.generate(4, (_) => GlobalKey<FormState>());
  final currentStep = 0.obs;
  final loadState = UIState<RegistrationModel>.initial().obs;
  final relations = <NomineeRelationModel>[].obs;
  final nominees = <NomineeFormRow>[].obs;
  final mobile = ''.obs;
  final dateOfBirth = RxnString();
  final photoUrl = RxnString();
  final saving = false.obs;
  final submitting = false.obs;
  final failure = Rxn<Failure>();

  final nameBnController = TextEditingController();
  final nameEnController = TextEditingController();
  final guardianController = TextEditingController();
  final nidController = TextEditingController();
  final emailController = TextEditingController();
  final addressController = TextEditingController();
  final sharesController = TextEditingController();

  String _idempotencyKey = const Uuid().v4();

  @override
  void onInit() {
    super.onInit();
    load();
  }

  Future<void> load() async {
    loadState.value = UIState.loading();
    final relationList = await repository.relations();
    final result = await repository.getRegistration();

    if (result.registration == null) {
      loadState.value = UIState.error(result.failure ?? relationList.failure ?? const UnknownFailure());
      return;
    }

    relations.assignAll(relationList.relations);
    final data = result.registration!.data;
    nameBnController.text = data.nameBn ?? '';
    nameEnController.text = data.nameEn ?? '';
    guardianController.text = data.guardianName ?? '';
    nidController.text = data.nid ?? '';
    emailController.text = data.email ?? '';
    addressController.text = data.address ?? '';
    sharesController.text = data.requestedShares?.toString() ?? '';
    dateOfBirth.value = data.dateOfBirth;
    photoUrl.value = data.photoUrl;
    mobile.value = data.mobile;
    nominees.assignAll(data.nominees.isEmpty ? [NomineeFormRow()] : data.nominees.map(NomineeFormRow.fromModel));
    loadState.value = UIState.success(result.registration!);
  }

  String? errorFor(String field) => failure.value?.validationErrors?[field]?.first;

  int get nomineeTotalHundredths => nominees.fold(0, (total, row) => total + (percentToHundredths(row.shareController.text) ?? 0));

  void addNominee() => nominees.add(NomineeFormRow());

  void removeNominee(int index) {
    if (nominees.length <= 1) return;
    nominees.removeAt(index).dispose();
  }

  String? _text(TextEditingController controller) {
    final value = controller.text.trim();
    return value.isEmpty ? null : value;
  }

  /// The fields that belong to [step], in the API's names.
  Map<String, dynamic> fieldsFor(int step) => switch (step) {
        0 => {
            'name_bn': _text(nameBnController),
            'name_en': _text(nameEnController),
            'guardian_name': _text(guardianController),
            'nid': _text(nidController) == null ? null : BanglaNumberUtil.toEnglish(_text(nidController)!),
            'date_of_birth': dateOfBirth.value,
          },
        1 => {'email': _text(emailController), 'address': _text(addressController)},
        2 => {'nominees': nominees.map((row) => row.toJson()).toList()},
        3 => {'requested_shares': int.tryParse(BanglaNumberUtil.toEnglish(sharesController.text.trim()))},
        _ => const {},
      };

  /// Validates and saves the current step; moves on when the server accepted it.
  Future<bool> next() async {
    final step = currentStep.value;
    if (step >= formKeys.length) return false;
    if (!skipValidationForTests && !(formKeys[step].currentState?.validate() ?? false)) return false;

    saving.value = true;
    failure.value = null;
    final result = await repository.saveDraft(fieldsFor(step));
    saving.value = false;

    if (result.failure != null) {
      failure.value = result.failure;
      return false;
    }

    currentStep.value = step + 1;
    return true;
  }

  void back() {
    if (currentStep.value > 0) currentStep.value--;
  }

  /// From the review step: jump back to a step to edit it.
  void goTo(int step) {
    if (step < currentStep.value) currentStep.value = step;
  }

  /// Compresses and uploads the photo; returns a translation key for the problem, or null.
  Future<String?> attachPhoto(String path) async {
    final compressed = await ImageCompressor.compressToLimit(path);
    if (compressed == null) return 'pay_proof_unreadable';

    saving.value = true;
    final result = await repository.uploadPhoto(compressed);
    saving.value = false;

    if (result.failure != null) {
      failure.value = result.failure;
      return result.failure!.message;
    }

    photoUrl.value = result.registration!.data.photoUrl;
    return null;
  }

  /// True when the registration was submitted (it now waits for the first approver).
  Future<bool> submit() async {
    if (submitting.value) return false;

    submitting.value = true;
    failure.value = null;
    final result = await repository.submit(_idempotencyKey);
    submitting.value = false;

    if (result.failure != null) {
      failure.value = result.failure;
      return false;
    }

    _idempotencyKey = const Uuid().v4();
    return true;
  }

  @override
  void onClose() {
    for (final controller in [nameBnController, nameEnController, guardianController, nidController, emailController, addressController, sharesController]) {
      controller.dispose();
    }
    for (final row in nominees) {
      row.dispose();
    }
    super.onClose();
  }
}
```

- [ ] **Step 5: Run the tests**

Run: `flutter test test/modules/registration_form_controller_test.dart && flutter analyze`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/modules/registration/controller test/modules/registration_form_controller_test.dart
git commit -m "Registration: form controller with per-step drafts and idempotent submit"
```

---

### Task 6: Registration form page

**Files:**
- Create: `lib/modules/registration/view/registration_form_page.dart`, `lib/modules/registration/view/widgets/nominee_card.dart`
- Modify: `lib/app/routes/app_pages.dart`
- Test: `test/modules/registration_form_page_test.dart`

**Interfaces:**
- Consumes: `RegistrationFormController` (Task 5), `AppStepIndicator` (Task 3), `AppTextField`, `AppButton`, `AppConfirmationDialog`, `AppLoading`, `AppErrorState`, `AppValidator` (existing).

- [ ] **Step 1: Write the failing widget test** — `test/modules/registration_form_page_test.dart`

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:somobay_somiti_mobile_app/app/localization/app_translations.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/controller/registration_form_controller.dart';
import 'package:somobay_somiti_mobile_app/modules/registration/view/registration_form_page.dart';

import 'registration_form_controller_test.dart' show RecordingRegistrationRepository;

void main() {
  testWidgets('walks from personal details to contact after saving', (tester) async {
    final repository = RecordingRegistrationRepository();
    Get.put(RegistrationFormController(repository: repository));

    await tester.pumpWidget(GetMaterialApp(translations: AppTranslations(), locale: const Locale('en', 'US'), home: const RegistrationFormPage()));
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextFormField, 'Name (English)'), 'Karim Mia');
    await tester.tap(find.text('Save and continue'));
    await tester.pumpAndSettle();

    expect(repository.saved.single['name_en'], 'Karim Mia');
    expect(find.text('Email'), findsOneWidget);

    Get.reset();
  });
}
```
(`AppTextField` renders a `TextFormField` with its `label`; if it renders the label differently, find by the `Key('field_name_en')` given below.)

- [ ] **Step 2: Run to verify it fails**

Run: `flutter test test/modules/registration_form_page_test.dart`
Expected: FAIL — page not found.

- [ ] **Step 3: Nominee card** — `view/widgets/nominee_card.dart`

```dart
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../../core/utils/app_validator.dart';
import '../../../../core/widgets/app_card.dart';
import '../../../../core/widgets/app_text_fields.dart';
import '../../controller/nominee_form_row.dart';
import '../../controller/registration_form_controller.dart';
import '../../model/registration_model.dart';

class NomineeCard extends StatelessWidget {
  final int index;
  final NomineeFormRow row;
  final List<NomineeRelationModel> relations;
  final VoidCallback? onRemove;
  final VoidCallback onShareChanged;

  const NomineeCard({super.key, required this.index, required this.row, required this.relations, required this.onRemove, required this.onShareChanged});

  @override
  Widget build(BuildContext context) {
    return AppCard(
      margin: const EdgeInsets.only(bottom: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text('${'registration_nominee'.tr} ${index + 1}', style: Theme.of(context).textTheme.titleMedium)),
          if (onRemove != null) TextButton(onPressed: onRemove, child: Text('registration_nominee_remove'.tr)),
        ]),
        AppTextField(label: 'registration_nominee_name'.tr, controller: row.nameController, isRequired: true, validator: (v) => AppValidator.validateRequired(v)),
        const SizedBox(height: 12),
        Obx(() => DropdownButtonFormField<int>(
              initialValue: row.relationId.value,
              decoration: InputDecoration(labelText: 'registration_nominee_relation'.tr),
              items: [for (final relation in relations) DropdownMenuItem(value: relation.id, child: Text(relation.label))],
              onChanged: (value) => row.relationId.value = value,
              validator: (value) => value == null ? 'registration_required'.tr : null,
            )),
        const SizedBox(height: 12),
        AppTextField(label: 'registration_nid'.tr, controller: row.nidController, isRequired: true, keyboardType: TextInputType.number, validator: AppValidator.validateNID),
        const SizedBox(height: 12),
        AppTextField(
          label: 'registration_mobile'.tr,
          controller: row.mobileController,
          keyboardType: TextInputType.phone,
          validator: (v) => (v == null || v.trim().isEmpty) ? null : AppValidator.validatePhone(v),
        ),
        const SizedBox(height: 12),
        AppTextField(
          label: 'registration_nominee_share'.tr,
          controller: row.shareController,
          isRequired: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          validator: (v) => percentToHundredths(v ?? '') == null ? 'registration_required'.tr : null,
          onChanged: (_) => onShareChanged(),
        ),
      ]),
    );
  }
}
```
(Match `validateRequired`/`validateNID` signatures in `app_validator.dart`; check `AppTextField` has `onChanged` — if not, add an optional `ValueChanged<String>? onChanged` passed to its `TextFormField`. `DropdownButtonFormField.initialValue` is `value` on older Flutter — use what the SDK in `pubspec.yaml` accepts.)

- [ ] **Step 4: Page** — `view/registration_form_page.dart`

```dart
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_text_styles.dart';
import '../../../core/utils/app_validator.dart';
import '../../../core/utils/snackbar_margin.dart';
import '../../../core/widgets/app_app_bar.dart';
import '../../../core/widgets/app_buttons.dart';
import '../../../core/widgets/app_card.dart';
import '../../../core/widgets/app_dialogs.dart';
import '../../../core/widgets/app_error_state.dart';
import '../../../core/widgets/app_loading.dart';
import '../../../core/widgets/app_step_indicator.dart';
import '../../../core/widgets/app_text_fields.dart';
import '../controller/registration_form_controller.dart';
import 'widgets/nominee_card.dart';

class RegistrationFormPage extends GetView<RegistrationFormController> {
  const RegistrationFormPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppAppBar(title: 'registration_title'.tr),
      body: Obx(() {
        final state = controller.loadState.value;
        if (state.isLoading || state.isInitial) return const AppLoading();
        if (state.isError) return AppErrorState(failure: state.failure, onRetry: controller.load);

        final step = controller.currentStep.value;

        return Column(children: [
          AppStepIndicator(labels: RegistrationFormController.stepLabels.map((key) => key.tr).toList(), current: step, onTap: controller.goTo),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: switch (step) {
                0 => _personal(context),
                1 => _contact(),
                2 => _nominees(),
                3 => _shares(),
                _ => _review(),
              },
            ),
          ),
          _bottomBar(),
        ]);
      }),
    );
  }

  Widget _error(String field) {
    final message = controller.errorFor(field);
    return message == null ? const SizedBox.shrink() : Padding(padding: const EdgeInsets.only(top: 4), child: Text(message, style: AppTextStyles.bodySmall.copyWith(color: AppColors.error)));
  }

  Widget _personal(BuildContext context) => Form(
        key: controller.formKeys[0],
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Center(
            child: Obx(() => GestureDetector(
                  onTap: _pickPhoto,
                  child: CircleAvatar(
                    radius: 40,
                    backgroundImage: controller.photoUrl.value == null ? null : NetworkImage(controller.photoUrl.value!),
                    child: controller.photoUrl.value == null ? const Icon(Icons.add_a_photo) : null,
                  ),
                )),
          ),
          TextButton(onPressed: _pickPhoto, child: Text('registration_photo_pick'.tr)),
          AppTextField(key: const Key('field_name_bn'), label: 'registration_name_bn'.tr, controller: controller.nameBnController, isRequired: true, validator: (v) => AppValidator.validateRequired(v)),
          _error('name_bn'),
          const SizedBox(height: 12),
          AppTextField(key: const Key('field_name_en'), label: 'registration_name_en'.tr, controller: controller.nameEnController, isRequired: true, validator: (v) => AppValidator.validateRequired(v)),
          _error('name_en'),
          const SizedBox(height: 12),
          AppTextField(label: 'registration_guardian'.tr, controller: controller.guardianController),
          const SizedBox(height: 12),
          AppTextField(
            label: 'registration_nid'.tr,
            controller: controller.nidController,
            keyboardType: TextInputType.number,
            validator: (v) => (v == null || v.trim().isEmpty) ? null : AppValidator.validateNID(v),
          ),
          _error('nid'),
          const SizedBox(height: 12),
          Obx(() => ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text('registration_dob'.tr),
                subtitle: Text(controller.dateOfBirth.value ?? '—'),
                trailing: const Icon(Icons.calendar_today),
                onTap: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: DateTime(1990),
                    firstDate: DateTime(1920),
                    lastDate: DateTime.now(),
                  );
                  if (picked != null) controller.dateOfBirth.value = picked.toIso8601String().substring(0, 10);
                },
              )),
          _error('date_of_birth'),
        ]),
      );

  Widget _contact() => Form(
        key: controller.formKeys[1],
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          AppTextField(label: 'registration_mobile'.tr, controller: TextEditingController(text: controller.mobile.value), readOnly: true),
          const SizedBox(height: 12),
          AppTextField(label: 'registration_email'.tr, controller: controller.emailController, keyboardType: TextInputType.emailAddress),
          _error('email'),
          const SizedBox(height: 12),
          AppTextField(label: 'registration_address'.tr, controller: controller.addressController, maxLines: 3),
          _error('address'),
        ]),
      );

  Widget _nominees() => Form(
        key: controller.formKeys[2],
        child: Obx(() {
          final total = controller.nomineeTotalHundredths;
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            for (var i = 0; i < controller.nominees.length; i++)
              NomineeCard(
                index: i,
                row: controller.nominees[i],
                relations: controller.relations,
                onRemove: controller.nominees.length > 1 ? () => controller.removeNominee(i) : null,
                onShareChanged: controller.nominees.refresh,
              ),
            OutlinedButton.icon(onPressed: controller.addNominee, icon: const Icon(Icons.person_add), label: Text('registration_nominee_add'.tr)),
            const SizedBox(height: 8),
            Text(
              'registration_nominee_total'.trParams({'total': '${total ~/ 100}${total % 100 == 0 ? '' : '.${(total % 100).toString().padLeft(2, '0')}'}'}),
              style: AppTextStyles.titleMedium.copyWith(color: total == 10000 ? AppColors.success : AppColors.error),
            ),
            _error('nominees'),
          ]);
        }),
      );

  Widget _shares() => Form(
        key: controller.formKeys[3],
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          AppTextField(
            label: 'registration_shares_requested'.tr,
            controller: controller.sharesController,
            keyboardType: TextInputType.number,
            isRequired: true,
            validator: (v) => (int.tryParse(v ?? '') ?? 0) < 1 ? 'registration_required'.tr : null,
          ),
          const SizedBox(height: 8),
          Text('registration_shares_help'.tr, style: AppTextStyles.bodySmall.copyWith(color: AppColors.textSecondary)),
          _error('requested_shares'),
        ]),
      );

  Widget _review() {
    Widget section(int step, String title, List<String> lines) => AppCard(
          margin: const EdgeInsets.only(bottom: 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(title.tr, style: AppTextStyles.titleMedium)),
              TextButton(onPressed: () => controller.goTo(step), child: Text('registration_edit'.tr)),
            ]),
            for (final line in lines) Text(line, style: AppTextStyles.bodyMedium),
          ]),
        );

    String relationOf(int? id) => controller.relations.firstWhereOrNull((r) => r.id == id)?.label ?? '—';

    return Column(children: [
      section(0, 'registration_step_personal', [
        controller.nameBnController.text,
        controller.nameEnController.text,
        if (controller.guardianController.text.isNotEmpty) controller.guardianController.text,
        if (controller.nidController.text.isNotEmpty) '${'registration_nid'.tr}: ${controller.nidController.text}',
        if (controller.dateOfBirth.value != null) '${'registration_dob'.tr}: ${controller.dateOfBirth.value}',
      ]),
      section(1, 'registration_step_contact', [controller.mobile.value, controller.emailController.text, controller.addressController.text].where((s) => s.isNotEmpty).toList()),
      section(2, 'registration_step_nominees', [
        for (final row in controller.nominees) '${row.nameController.text} (${relationOf(row.relationId.value)}) — ${row.shareController.text}%',
      ]),
      section(3, 'registration_step_shares', [controller.sharesController.text]),
      if (controller.failure.value != null) Text(controller.failure.value!.message.tr, style: AppTextStyles.bodyMedium.copyWith(color: AppColors.error)),
    ]);
  }

  Widget _bottomBar() => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Obx(() {
            final step = controller.currentStep.value;
            final last = step == RegistrationFormController.stepLabels.length - 1;
            return Row(children: [
              if (step > 0) ...[
                Expanded(child: AppButton(text: 'registration_back'.tr, variant: ButtonVariant.outlined, onPressed: controller.back)),
                const SizedBox(width: 12),
              ],
              Expanded(
                flex: 2,
                child: AppButton(
                  text: last ? 'registration_submit'.tr : 'registration_next'.tr,
                  isLoading: controller.saving.value || controller.submitting.value,
                  onPressed: last ? _submit : _next,
                ),
              ),
            ]);
          }),
        ),
      );

  Future<void> _next() async {
    if (controller.currentStep.value == 2 && controller.nomineeTotalHundredths != 10000) {
      Get.snackbar('common_error_title'.tr, 'registration_total_not_100'.tr, snackPosition: SnackPosition.BOTTOM, margin: snackbarMargin());
      return;
    }
    final saved = await controller.next();
    if (!saved && controller.failure.value != null && controller.failure.value!.validationErrors == null) {
      Get.snackbar('common_error_title'.tr, controller.failure.value!.message.tr, snackPosition: SnackPosition.BOTTOM, margin: snackbarMargin());
    }
  }

  Future<void> _submit() async {
    final confirmed = await AppConfirmationDialog.show(
      title: 'registration_submit_confirm_title'.tr,
      message: 'registration_submit_confirm_message'.tr,
      confirmText: 'registration_submit'.tr,
    );
    if (confirmed != true) return;

    if (await controller.submit()) {
      Get.back();
      Get.snackbar('common_success_title'.tr, 'registration_submitted'.tr, snackPosition: SnackPosition.BOTTOM, margin: snackbarMargin());
    } else if (controller.failure.value != null) {
      Get.snackbar('common_error_title'.tr, controller.failure.value!.message.tr, snackPosition: SnackPosition.BOTTOM, margin: snackbarMargin());
    }
  }

  Future<void> _pickPhoto() async {
    final result = await FilePicker.pickFiles(type: FileType.image);
    final path = result?.files.single.path;
    if (path == null) return;
    final problem = await controller.attachPhoto(path);
    if (problem != null) {
      Get.snackbar('common_error_title'.tr, problem.tr, snackPosition: SnackPosition.BOTTOM, margin: snackbarMargin());
    }
  }
}
```
(Confirm `ButtonVariant.outlined`, `AppTextField.readOnly`/`maxLines` and `FilePicker.pickFiles` call style against the existing widgets and `pay_online_page.dart`, which uses `FilePicker.pickFiles(...)` statically; mirror it exactly.)

`app_pages.dart` — add the form page:

```dart
    GetPage(name: AppRoutes.registrationForm, page: () => const RegistrationFormPage(), binding: RegistrationFormBinding()),
```

- [ ] **Step 5: Run the tests**

Run: `flutter test && flutter analyze`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/modules/registration lib/app/routes/app_pages.dart test/modules/registration_form_page_test.dart lib/core/widgets/app_text_fields.dart lib/app/localization
git commit -m "Registration: five-step form with review and confirmation"
```

---

### Task 7: Documentation and end-to-end check

**Files:**
- Modify: `docs/MEMBER_FEATURES.md`, `docs/MEMBER_API_SPECIFICATION.md`, `docs/MEMBER_NAVIGATION.md`

- [ ] **Step 1: Docs**
  - `MEMBER_FEATURES.md`: move "self-registration" from "Not in the app (no backend support)" into the supported list. Describe it as: invited by the office (mobile + password), five-step form, server-driven approval status, approval opens the dashboard.
  - `MEMBER_API_SPECIFICATION.md`: add `GET /config/nominee-relations`, `GET|PUT /registration`, `POST /registration/photo`, `POST /registration/submit`, the `account_type` field on login/refresh/me, and the 403 `member_only` answer, copying the shapes from the backend spec §8.
  - `MEMBER_NAVIGATION.md`: add the routes `/registration` and `/registration/form` and the rule "start-up/login: applicant → registration status, member → dashboard".

- [ ] **Step 2: Full check**

Run: `flutter analyze && flutter test`
Expected: no issues; all tests pass.

- [ ] **Step 3: End to end against the local backend** (backend plan done, `composer run dev` on port 8002)

Run: `flutter run --flavor development -t lib/main_development.dart --dart-define=API_BASE_URL=http://10.0.2.2:8002`
1. In `/admin` invite `01811111111` / `secret-123`.
2. App: sign in → the status screen shows "আপনার নিবন্ধন অসম্পূর্ণ" (incomplete) with the Secretary → President steps.
3. Complete registration → fill the five steps (close and reopen the app midway: the saved steps are still filled) → submit → the status shows "সম্পাদক-এর অনুমোদনের অপেক্ষায়" (waiting for the Secretary).
4. Admin: the secretary approves; the president approves with shares + month.
5. App: pull to refresh → the dashboard opens with the new member number, no sign-in.
6. Switch the language to English → the labels come back in English from the server.

- [ ] **Step 4: Commit**

```bash
git add docs/MEMBER_FEATURES.md docs/MEMBER_API_SPECIFICATION.md docs/MEMBER_NAVIGATION.md
git commit -m "Docs: member self-registration in the app"
```
