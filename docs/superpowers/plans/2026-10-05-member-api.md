# Member API (plan 1 of 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A token-authenticated JSON API at `/api/v1` exposing exactly the member portal's capabilities, in the envelope the Flutter app already expects.

**Architecture:** Laravel Sanctum issues paired access/refresh tokens per login "family". Thin controllers in `app/Http/Controllers/Api/Member` call the same domain services the Filament member panel uses and return API Resources; a small `ApiResponse` helper and an exception renderer give every response the `{success, statusCode, message, data, errors, meta}` envelope. Every data query is scoped to the member resolved from the token.

**Tech Stack:** Laravel 13.34, PHP 8.5, PostgreSQL, laravel/sanctum (new), Pest.

**Spec:** `docs/superpowers/specs/2026-10-05-member-mobile-api-design.md` (§4 and §7 backend). Plan 2 (docs + Flutter) is written after this plan ships.

## Global Constraints

- `declare(strict_types=1);` everywhere; Pint; Larastan level 8, no baseline.
- No business logic in controllers: validate (Form Request), call one service/Action, return a Resource.
- Money in JSON is always `{"poisha": int, "display": string}` via `ApiValue::money()`; never a float, never a bare number.
- Dates `Y-m-d`, months `Y-m`, timestamps ISO-8601 in Asia/Dhaka.
- The member always comes from the token. No endpoint accepts a member id. Route ids are looked up within the member's own rows (404 otherwise).
- Exited members are refused; inactive members are allowed (same as the portal's `PortalAccounts::activeMemberOf`).
- Every user-facing message through `__()` in both `lang/bn/api.php` and `lang/en/api.php`.
- Access token 60 minutes, ability `member`; refresh token 30 days, ability `refresh`, single use.

## Review Focus

1. **Another member's id in a URL** (`/payments/{id}` of member B, receipt of B) — expected 404, never B's data. (Task 5, plus the sweep in Task 8.)
2. **A spent refresh token presented again** (stolen or replayed) — expected 401 and every token of that member revoked. (Task 2.)
3. **A refresh token used as an access token, or an access token sent to refresh** — expected 401. (Task 2.)
4. **Pay-online retried with the same idempotency key** (flaky network) — same payment returned, no duplicate, no orphaned proof file; same key with a different amount → 409. (Task 5.)
5. **`Accept-Language` missing or unknown** (e.g. `fr`, `bn-BD`) — expected Bangla for missing/unknown, `bn-BD` treated as `bn`, `en-US` as `en`. (Task 1.)

---

## File Structure

| File | Responsibility |
|------|----------------|
| `routes/api.php` | all `/api/v1` routes |
| `bootstrap/app.php` | register api routes, middleware aliases, API exception rendering |
| `app/Http/Api/ApiResponse.php` | envelope builder (ok, created, paginated, error) |
| `app/Http/Api/ApiValue.php` | money / enum / date / month formatting for JSON |
| `app/Http/Api/ApiExceptionRenderer.php` | exceptions → envelope + status |
| `app/Http/Middleware/SetApiLocale.php` | `Accept-Language` → app locale |
| `app/Http/Middleware/EnsureMemberAccess.php` | resolve the member from the token; refuse exited |
| `app/Domain/Members/Portal/MemberTokens.php` | issue / refresh / revoke token pairs |
| `app/Http/Controllers/Api/Member/*Controller.php` | one controller per area |
| `app/Http/Requests/Api/*.php` | request validation |
| `app/Http/Resources/Api/*.php` | response shapes |
| `lang/{bn,en}/api.php` | API messages |
| `tests/Feature/Api/*.php` | tests; helpers in `tests/Pest.php` |

---

### Task 1: Sanctum, envelope, locale, errors, `config/somiti-info`

**Files:**
- Run: `php artisan install:api --no-interaction` (adds `laravel/sanctum`, `routes/api.php`, the `personal_access_tokens` migration, and `->withRouting(api: ...)`). If it asks to run migrations, answer no and run them yourself.
- Modify: `app/Models/User.php` (add `use Laravel\Sanctum\HasApiTokens;` trait), `bootstrap/app.php`, `routes/api.php`
- Create: `app/Http/Api/ApiResponse.php`, `app/Http/Api/ApiValue.php`, `app/Http/Api/ApiExceptionRenderer.php`, `app/Http/Middleware/SetApiLocale.php`, `app/Http/Controllers/Api/Member/ConfigController.php`, `lang/{bn,en}/api.php`
- Test: `tests/Feature/Api/ApiFoundationTest.php`

**Interfaces:**
- Produces:
  - `ApiResponse::ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse`
  - `ApiResponse::paginated(LengthAwarePaginator $page, callable $map): JsonResponse` — `data` = mapped items, `meta` = `{current_page, last_page, per_page, total}`
  - `ApiResponse::error(string $message, int $status, ?array $errors = null): JsonResponse`
  - `ApiValue::money(?Money $money): ?array{poisha: int, display: string}`
  - `ApiValue::enum(?BackedEnum $value): ?array{value: string|int, label: string, color: string|null}` (label/color from `HasLabel`/`HasColor` when implemented)
  - `ApiValue::date(?DateTimeInterface $date): ?string` (`Y-m-d`), `ApiValue::month(?YearMonth $month): ?string` (`Y-m`), `ApiValue::time(?DateTimeInterface $at): ?string` (ISO-8601 Asia/Dhaka)
  - Route group prefix `v1` (Laravel adds `api/`), middleware `api.locale`.

- [ ] **Step 1: Install Sanctum**

```bash
php artisan install:api --no-interaction
php artisan migrate --no-interaction
```

Confirm `composer.json` now requires `laravel/sanctum` and `bootstrap/app.php` has `api: __DIR__.'/../routes/api.php'`. In `config/sanctum.php` set `'expiration' => null` (every token carries its own `expires_at`) and `'guard' => []` (API is token-only; the SPA cookie path is not used). Add `HasApiTokens` to `User`.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Settings\Actions\UpdateSomitiProfile;
use App\Domain\Settings\Data\SomitiProfileData;
use App\Enums\Role;

it('returns the society details in the app envelope, in Bangla by default', function (): void {
    app(UpdateSomitiProfile::class)(userWithRole(Role::SuperAdmin), new SomitiProfileData(nameBn: 'সবুজ সমবায় সমিতি', nameEn: 'Sabuj Society', phone: '01712345678'));
    config(['somiti.portal_otp' => true]);

    $this->getJson('/api/v1/config/somiti-info')
        ->assertOk()
        ->assertExactJsonStructure(['success', 'statusCode', 'message', 'data' => ['name', 'name_bn', 'name_en', 'registration_no', 'address', 'phone', 'email', 'logo_url', 'otp_enabled'], 'errors', 'meta'])
        ->assertJson(['success' => true, 'statusCode' => 200, 'data' => ['name' => 'সবুজ সমবায় সমিতি', 'otp_enabled' => true], 'errors' => null, 'meta' => null]);
});

it('picks the language from Accept-Language and falls back to Bangla', function (?string $header, string $name): void {
    app(UpdateSomitiProfile::class)(userWithRole(Role::SuperAdmin), new SomitiProfileData(nameBn: 'সবুজ', nameEn: 'Sabuj'));

    $this->withHeaders($header === null ? [] : ['Accept-Language' => $header])
        ->getJson('/api/v1/config/somiti-info')
        ->assertJsonPath('data.name', $name);
})->with([[null, 'সবুজ'], ['en', 'Sabuj'], ['en-US,en;q=0.9', 'Sabuj'], ['bn-BD', 'সবুজ'], ['fr', 'সবুজ']]);

it('answers unknown routes and wrong methods in the envelope', function (): void {
    $this->getJson('/api/v1/nope')->assertNotFound()->assertJson(['success' => false, 'statusCode' => 404, 'data' => null]);
    $this->postJson('/api/v1/config/somiti-info')->assertStatus(405)->assertJson(['success' => false, 'statusCode' => 405]);
});

it('formats money, enums and months for the app', function (): void {
    app()->setLocale('bn');
    expect(\App\Http\Api\ApiValue::money(\App\Support\Money\Money::ofTaka('1005.25')))->toBe(['poisha' => 100525, 'display' => \App\Filament\Support\Display::money(\App\Support\Money\Money::ofTaka('1005.25'))])
        ->and(\App\Http\Api\ApiValue::money(null))->toBeNull()
        ->and(\App\Http\Api\ApiValue::month(\App\Support\Time\YearMonth::parse('2026-07')))->toBe('2026-07')
        ->and(\App\Http\Api\ApiValue::enum(\App\Domain\Contributions\Enums\DueStatus::Open))->toMatchArray(['value' => 'open', 'label' => \App\Domain\Contributions\Enums\DueStatus::Open->getLabel()]);
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiFoundationTest.php`
Expected: FAIL (404 for `/api/v1/config/somiti-info`; `ApiValue` missing).

- [ ] **Step 4: Implement the helpers**

`app/Http/Api/ApiValue.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Filament\Support\Display;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * JSON shapes the mobile app relies on. Money is exact poisha plus the backend's own formatting,
 * so the app never parses or rounds an amount.
 */
final class ApiValue
{
    /**
     * @return array{poisha: int, display: string}|null
     */
    public static function money(?Money $money): ?array
    {
        return $money === null ? null : ['poisha' => $money->poisha, 'display' => Display::money($money)];
    }

    /**
     * @return array{value: string|int, label: string, color: string|null}|null
     */
    public static function enum(?BackedEnum $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $color = $value instanceof HasColor ? $value->getColor() : null;

        return [
            'value' => $value->value,
            'label' => $value instanceof HasLabel ? (string) $value->getLabel() : (string) $value->value,
            'color' => is_string($color) ? $color : null,
        ];
    }

    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    public static function month(?YearMonth $month): ?string
    {
        return $month === null ? null : substr($month->toDateString(), 0, 7);
    }

    public static function time(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(YearMonth::TIMEZONE)->toIso8601String();
    }
}
```

`app/Http/Api/ApiResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The envelope the Flutter app's ApiResponse<T> reads: success, statusCode, message, data, errors, meta.
 */
final class ApiResponse
{
    public const int PER_PAGE = 20;

    public static function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return self::make(true, $status, $message ?? __('api.ok'), $data);
    }

    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $page
     * @param  callable(T): mixed  $map
     */
    public static function paginated(LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return self::make(true, 200, __('api.ok'), array_map($map, $page->items()), null, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public static function error(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return self::make(false, $status, $message, null, $errors);
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, int>|null  $meta
     */
    private static function make(bool $success, int $status, string $message, mixed $data, ?array $errors = null, ?array $meta = null): JsonResponse
    {
        return new JsonResponse([
            'success' => $success,
            'statusCode' => $status,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }
}
```

`app/Http/Api/ApiExceptionRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every exception on /api/* into the app envelope with a localised, non-technical message.
 */
final class ApiExceptionRenderer
{
    /** Domain rule keys that mean "conflict" rather than "invalid". */
    private const array CONFLICTS = ['payments.errors.idempotency_conflict'];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(__('api.errors.validation'), 422, $e->errors()),
            $e instanceof DomainRuleViolation => ApiResponse::error($e->getMessage(), in_array($e->translationKey, self::CONFLICTS, true) ? 409 : 422),
            $e instanceof AuthenticationException => ApiResponse::error(__('api.errors.unauthenticated'), 401),
            $e instanceof AuthorizationException => ApiResponse::error(__('api.errors.forbidden'), 403),
            $e instanceof ModelNotFoundException => ApiResponse::error(__('api.errors.not_found'), 404),
            $e instanceof ThrottleRequestsException => ApiResponse::error(__('api.errors.throttled'), 429),
            $e instanceof HttpExceptionInterface => ApiResponse::error(match ($e->getStatusCode()) {
                401 => __('api.errors.unauthenticated'),
                403 => __('api.errors.forbidden'),
                404 => __('api.errors.not_found'),
                405 => __('api.errors.method'),
                default => __('api.errors.server'),
            }, $e->getStatusCode()),
            default => ApiResponse::error(__('api.errors.server'), 500),
        };
    }
}
```

`ThrottleRequestsException` extends `HttpException`, so keep it above the `HttpExceptionInterface` arm. Unexpected exceptions are still reported by Laravel before rendering.

`app/Http/Middleware/SetApiLocale.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept-Language → bn or en (anything else, or nothing, is Bangla — the society's language).
 */
final class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $first = strtolower(substr(trim((string) $request->header('Accept-Language', 'bn')), 0, 2));
        app()->setLocale($first === 'en' ? 'en' : 'bn');

        return $next($request);
    }
}
```

In `bootstrap/app.php`: keep the existing `withRouting` lines and the `api:` entry from `install:api`; in `withMiddleware` add

```php
        $middleware->alias([
            'api.locale' => \App\Http\Middleware\SetApiLocale::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'member' => \App\Http\Middleware\EnsureMemberAccess::class,
        ]);
```

(`EnsureMemberAccess` is created in Task 2; add its alias then if Larastan complains now.) In `withExceptions` keep `shouldRenderJsonWhen` and add `$exceptions->render(app(\App\Http\Api\ApiExceptionRenderer::class));` — use `new \App\Http\Api\ApiExceptionRenderer` since the container may not be ready; the class has no dependencies.

`app/Http/Controllers/Api/Member/ConfigController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Portal\LoginCodes;
use App\Domain\Settings\Models\SomitiProfile;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;

final class ConfigController extends Controller
{
    public function somitiInfo(): JsonResponse
    {
        $profile = SomitiProfile::current();

        return ApiResponse::ok([
            'name' => $profile->displayName(),
            'name_bn' => $profile->name_bn,
            'name_en' => $profile->name_en,
            'registration_no' => $profile->registration_no,
            'address' => $profile->displayAddress(),
            'phone' => $profile->phone,
            'email' => $profile->email,
            'logo_url' => $profile->logo_path === null ? null : URL::temporarySignedRoute('api.logo', now()->addHour()),
            'otp_enabled' => (bool) config('somiti.portal_otp'),
        ]);
    }

    public function logo(): \Symfony\Component\HttpFoundation\Response
    {
        $profile = SomitiProfile::current();
        abort_if($profile->logo_path === null, 404);

        return \Illuminate\Support\Facades\Storage::disk(SomitiProfile::LOGO_DISK)->response($profile->logo_path);
    }
}
```

Remove the unused `LoginCodes` import. If `app/Http/Controllers/Controller.php` does not exist, extend nothing (plain final class).

`routes/api.php` (replace the generated content):

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Member\ConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api.locale')->group(function (): void {
    Route::get('config/somiti-info', [ConfigController::class, 'somitiInfo']);
    Route::get('config/logo', [ConfigController::class, 'logo'])->middleware('signed')->name('api.logo');
});

Route::fallback(fn () => \App\Http\Api\ApiResponse::error(__('api.errors.not_found'), 404));
```

`lang/en/api.php`:

```php
<?php

declare(strict_types=1);

return [
    'ok' => 'OK',
    'errors' => [
        'validation' => 'Please check the highlighted fields.',
        'unauthenticated' => 'Please sign in again.',
        'forbidden' => 'You do not have access to this.',
        'not_found' => 'Not found.',
        'method' => 'This action is not allowed here.',
        'throttled' => 'Too many attempts. Please wait a minute and try again.',
        'server' => 'Something went wrong. Please try again later.',
    ],
];
```

`lang/bn/api.php` — same keys: `'ok' => 'ঠিক আছে'`, `validation` → `'চিহ্নিত ঘরগুলো দেখে আবার চেষ্টা করুন।'`, `unauthenticated` → `'অনুগ্রহ করে আবার লগইন করুন।'`, `forbidden` → `'এটি দেখার অনুমতি আপনার নেই।'`, `not_found` → `'পাওয়া যায়নি।'`, `method` → `'এখানে এই কাজটি করা যায় না।'`, `throttled` → `'অনেকবার চেষ্টা হয়েছে। এক মিনিট পরে আবার চেষ্টা করুন।'`, `server` → `'কিছু একটা সমস্যা হয়েছে। পরে আবার চেষ্টা করুন।'`.

- [ ] **Step 5: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Member API: Sanctum, app envelope, locale and society info"
```

---

### Task 2: Login, codes, token pairs, refresh, logout, member access

**Files:**
- Create: `app/Domain/Members/Portal/MemberTokens.php`, `app/Domain/Members/Portal/MemberCredentials.php`, `app/Http/Middleware/EnsureMemberAccess.php`, `app/Http/Controllers/Api/Member/AuthController.php`, `app/Http/Requests/Api/LoginRequest.php`
- Modify: `app/Filament/Member/Pages/Auth/MemberLogin.php` (use `MemberCredentials` instead of its private `userFromPassword`/`member` so web and API share one check), `app/Domain/Members/Actions/SetPortalPassword.php` (revoke tokens), `app/Providers/AppServiceProvider.php` (rate limiters), `routes/api.php`, `lang/{bn,en}/api.php`
- Test: `tests/Feature/Api/ApiAuthTest.php`; add helper `memberToken()` to `tests/Pest.php`

**Interfaces:**
- Produces:
  - `MemberCredentials::byPassword(string $mobile, string $password): ?User` — null when no non-exited member has that mobile or the password is wrong
  - `MemberCredentials::byCode(string $mobile, string $code): ?User` — wraps `LoginCodes::verify` (throws its `DomainRuleViolation`), null for exited members
  - `MemberTokens::issue(User $user): array{access_token: string, refresh_token: string, token_type: string, expires_in: int}`
  - `MemberTokens::refresh(string $plainRefreshToken): array{...same}` — throws `AuthenticationException` when unknown, expired, not a refresh token, or already used (and then revokes every token of that user)
  - `MemberTokens::revokeFamily(PersonalAccessToken $accessToken): void`, `MemberTokens::revokeAll(User $user): void`
  - Request attribute `member` (`$request->attributes->get('member')`, a `Member`) set by `EnsureMemberAccess`; controllers read it via `self::member($request)` helper in a shared trait `App\Http\Controllers\Api\Member\Concerns\ResolvesMember`.
  - Test helper `memberToken(Member $member): string` (plain access token) in `tests/Pest.php`.

Token naming: access `access:{family}`, refresh `refresh:{family}`, where `family` is a UUID per login. A used refresh token is renamed `used:{family}` and expired (kept, so a replay is recognised).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Portal\PortalAccounts;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07', ['mobile' => '01712345678']);
    app(SetPortalPassword::class)(userWithRole(Role::Secretary), $this->member, 'secret-123');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function login(array $body): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/login', $body);
}

it('signs a member in with mobile and password and returns a token pair', function (): void {
    login(['mobile' => '+880 1712-345678', 'password' => 'secret-123'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'token_type', 'expires_in']])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_in', 3600);

    expect(PersonalAccessToken::query()->count())->toBe(2);
});

it('refuses a wrong password, an unknown mobile, an exited member and staff', function (): void {
    login(['mobile' => '01712345678', 'password' => 'nope'])->assertStatus(422)->assertJsonPath('errors.mobile.0', __('api.auth.failed'));
    login(['mobile' => '01899999999', 'password' => 'secret-123'])->assertStatus(422);

    $staff = userWithRole(Role::Accountant);
    login(['mobile' => '01700000000', 'password' => 'password'])->assertStatus(422);

    $this->member->forceFill(['status' => MemberStatus::Exited])->save();
    login(['mobile' => '01712345678', 'password' => 'secret-123'])->assertStatus(422);
});

it('lets an inactive member sign in, as the portal does', function (): void {
    $this->member->forceFill(['status' => MemberStatus::Inactive])->save();

    login(['mobile' => '01712345678', 'password' => 'secret-123'])->assertOk();
});

it('rate limits login attempts', function (): void {
    foreach (range(1, 5) as $i) {
        login(['mobile' => '01712345678', 'password' => 'wrong'])->assertStatus(422);
    }

    login(['mobile' => '01712345678', 'password' => 'secret-123'])->assertStatus(429)->assertJsonPath('success', false);
});

it('signs in with an SMS code only when codes are switched on', function (): void {
    config(['somiti.portal_otp' => false]);
    $this->postJson('/api/v1/auth/send-code', ['mobile' => '01712345678'])->assertNotFound();

    config(['somiti.portal_otp' => true]);
    fakeSms();
    $this->postJson('/api/v1/auth/send-code', ['mobile' => '01712345678'])->assertOk();
    $code = (string) preg_replace('/\D/', '', \App\Domain\Notifications\Models\SmsMessage::query()->latest('id')->first()->body ?? '');
    $code = substr(\App\Support\Bangla\BanglaNumber::toAscii($code), 0, 6);

    login(['mobile' => '01712345678', 'code' => $code])->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});

it('opens data endpoints with the access token only', function (): void {
    $tokens = login(['mobile' => '01712345678', 'password' => 'secret-123'])->json('data');

    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.member_no', $this->member->member_no);
    $this->withToken($tokens['refresh_token'])->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('expires access tokens after an hour and rotates them with the refresh token once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-10 10:00'));
    $tokens = login(['mobile' => '01712345678', 'password' => 'secret-123'])->json('data');

    $this->travelTo(CarbonImmutable::parse('2026-07-10 11:01')); // travelTo moves every clock Sanctum reads
    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    $fresh = $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data');
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertOk();

    // Replaying the spent refresh token revokes everything.
    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    $this->withToken($fresh['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('refuses an access token on the refresh endpoint', function (): void {
    $tokens = login(['mobile' => '01712345678', 'password' => 'secret-123'])->json('data');

    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['access_token']])->assertUnauthorized();
});

it('logs out this device only', function (): void {
    $phone = login(['mobile' => '01712345678', 'password' => 'secret-123'])->json('data');
    $tablet = login(['mobile' => '01712345678', 'password' => 'secret-123'])->json('data');

    $this->withToken($phone['access_token'])->postJson('/api/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($phone['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $phone['refresh_token']])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($tablet['access_token'])->getJson('/api/v1/auth/me')->assertOk();
});

it('signs the member out everywhere when staff set a new password or the member exits', function (): void {
    $tokens = login(['mobile' => '01712345678', 'password' => 'secret-123'])->json('data');
    app(SetPortalPassword::class)(userWithRole(Role::Secretary), $this->member, 'another-456');
    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    $tokens = login(['mobile' => '01712345678', 'password' => 'another-456'])->json('data');
    $this->member->forceFill(['status' => MemberStatus::Exited])->save();
    app('auth')->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/v1/auth/me')->assertForbidden();
    expect(app(PortalAccounts::class)->forMember($this->member)->tokens()->count())->toBe(0);
});
```

`fakeSms()` lives in `tests/Feature/Notifications/SmsTest.php`; move it into `tests/Pest.php` in this step so both files share it. Check the login-code SMS body format in `lang/bn/sms.php` (or wherever `SmsTemplateKey::LoginCode` text lives) and adjust the code extraction if the body has other digits; the cleanest is `Cache`/table lookup — look at how `tests/Feature/Portal/PortalTest.php` reads the code and copy that.

Add the test helper to `tests/Pest.php`:

```php
/**
 * A fresh access token for the member's portal account.
 */
function memberToken(\App\Domain\Members\Models\Member $member): string
{
    $user = app(\App\Domain\Members\Portal\PortalAccounts::class)->forMember($member);

    return app(\App\Domain\Members\Portal\MemberTokens::class)->issue($user)['access_token'];
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiAuthTest.php`
Expected: FAIL (404 on `/api/v1/auth/login`).

- [ ] **Step 3: Implement**

`app/Domain/Members/Portal/MemberCredentials.php` — move the portal's checks here (same rules: normalise mobile, member not exited, `Hash::check` on the portal user's password; codes through `LoginCodes::verify`):

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Illuminate\Support\Facades\Hash;

/**
 * Who a mobile number + password (or SMS code) belongs to, for the portal and the app alike.
 */
final class MemberCredentials
{
    public function __construct(
        private readonly PortalAccounts $accounts,
        private readonly LoginCodes $codes,
    ) {}

    public function byPassword(string $mobile, string $password): ?User
    {
        $member = $this->member($mobile);

        if ($member === null) {
            return null;
        }

        $user = $this->accounts->forMember($member);

        return Hash::check($password, $user->password) ? $user : null;
    }

    /**
     * @throws \App\Domain\Shared\Exceptions\DomainRuleViolation when the code is wrong, used or expired
     */
    public function byCode(string $mobile, string $code): ?User
    {
        $member = $this->codes->verify($mobile, $code);

        return $member->status === MemberStatus::Exited ? null : $this->accounts->forMember($member);
    }

    private function member(string $mobile): ?Member
    {
        $normalized = MobileNumber::normalize($mobile);

        return $normalized === null ? null : Member::query()
            ->where('mobile', $normalized)
            ->where('status', '!=', MemberStatus::Exited)
            ->first();
    }
}
```

In `MemberLogin`, replace `userFromPassword()` body with `return app(MemberCredentials::class)->byPassword((string) ($data['mobile'] ?? ''), (string) ($data['password'] ?? ''));` and in `userFromCode()` call `app(MemberCredentials::class)->byCode(...)` inside the existing try/catch; delete the private `member()` method. Run `php artisan test --compact tests/Feature/Portal` to prove the portal is unchanged.

`app/Domain/Members/Portal/MemberTokens.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * App sign-in tokens. Each login is a "family": a 60-minute access token (ability member) and a
 * 30-day refresh token (ability refresh). A refresh token works once; presenting a spent one again
 * means it was copied, so every token of that user is revoked.
 */
final class MemberTokens
{
    public const int ACCESS_MINUTES = 60;

    public const int REFRESH_DAYS = 30;

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(User $user, ?string $family = null): array
    {
        $family ??= (string) Str::uuid();
        $now = CarbonImmutable::now();

        $access = $user->createToken('access:'.$family, ['member'], $now->addMinutes(self::ACCESS_MINUTES));
        $refresh = $user->createToken('refresh:'.$family, ['refresh'], $now->addDays(self::REFRESH_DAYS));

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_MINUTES * 60,
        ];
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     *
     * @throws AuthenticationException
     */
    public function refresh(string $plainRefreshToken): array
    {
        return DB::transaction(function () use ($plainRefreshToken): array {
            $found = PersonalAccessToken::findToken($plainRefreshToken);
            $token = $found === null ? null : PersonalAccessToken::query()->whereKey($found->getKey())->lockForUpdate()->first();
            $user = $token?->tokenable;

            if ($token === null || ! $user instanceof User) {
                throw new AuthenticationException;
            }

            if (str_starts_with($token->name, 'used:')) {
                $this->revokeAll($user);

                throw new AuthenticationException;
            }

            if (! str_starts_with($token->name, 'refresh:') || ! $token->can('refresh') || ($token->expires_at !== null && $token->expires_at->isPast())) {
                throw new AuthenticationException;
            }

            $family = substr($token->name, strlen('refresh:'));
            $user->tokens()->where('name', 'access:'.$family)->delete();
            $token->forceFill(['name' => 'used:'.$family, 'expires_at' => CarbonImmutable::now()])->save();

            return $this->issue($user, $family);
        }, attempts: 3);
    }

    public function revokeFamily(PersonalAccessToken $accessToken): void
    {
        $family = substr($accessToken->name, strlen('access:'));
        $accessToken->tokenable?->tokens()->whereIn('name', ['access:'.$family, 'refresh:'.$family, 'used:'.$family])->delete();
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
```

`app/Http/Middleware/EnsureMemberAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Http\Api\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the member behind the token. An exited member (or a non-member account) is refused and
 * signed out everywhere. Controllers read the member from the request, never from input.
 */
final class EnsureMemberAccess
{
    public function __construct(
        private readonly PortalAccounts $accounts,
        private readonly MemberTokens $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $member = $user instanceof User ? $this->accounts->activeMemberOf($user) : null;

        if ($member === null) {
            if ($user instanceof User) {
                $this->tokens->revokeAll($user);
            }

            return ApiResponse::error(__('api.errors.forbidden'), 403);
        }

        $request->attributes->set('member', $member);

        return $next($request);
    }
}
```

`app/Http/Controllers/Api/Member/Concerns/ResolvesMember.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member\Concerns;

use App\Domain\Members\Models\Member;
use Illuminate\Http\Request;

trait ResolvesMember
{
    protected static function member(Request $request): Member
    {
        $member = $request->attributes->get('member');

        return $member instanceof Member ? $member : abort(403);
    }
}
```

`app/Http/Requests/Api/LoginRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:20'],
            'password' => ['required_without:code', 'nullable', 'string', 'max:200'],
            'code' => ['required_without:password', 'nullable', 'string', 'max:10'],
        ];
    }
}
```

`app/Http/Controllers/Api/Member/AuthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Portal\LoginCodes;
use App\Domain\Members\Portal\MemberCredentials;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Requests\Api\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController
{
    use ResolvesMember;

    public function __construct(
        private readonly MemberCredentials $credentials,
        private readonly MemberTokens $tokens,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $mobile = (string) $request->string('mobile');
        $usesCode = $request->filled('code');

        if ($usesCode && ! config('somiti.portal_otp')) {
            throw ValidationException::withMessages(['code' => __('api.auth.codes_off')]);
        }

        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($request, $mobile, $usesCode): ?User {
            try {
                $user = $usesCode
                    ? $this->credentials->byCode($mobile, (string) $request->string('code'))
                    : $this->credentials->byPassword($mobile, (string) $request->string('password'));
            } catch (DomainRuleViolation $violation) {
                throw ValidationException::withMessages(['code' => $violation->getMessage()]);
            }

            if ($user !== null) {
                $timebox->returnEarly();
            }

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        if ($user === null) {
            throw ValidationException::withMessages(['mobile' => __('api.auth.failed')]);
        }

        return ApiResponse::ok($this->tokens->issue($user), __('api.auth.signed_in'));
    }

    public function sendCode(Request $request, LoginCodes $codes): JsonResponse
    {
        abort_unless((bool) config('somiti.portal_otp'), 404);
        $request->validate(['mobile' => ['required', 'string', 'max:20']]);

        $codes->send((string) $request->string('mobile'), (string) $request->ip());

        return ApiResponse::ok(null, __('portal.login.code_sent'));
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => ['required', 'string']]);

        return ApiResponse::ok($this->tokens->refresh((string) $request->string('refresh_token')));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revokeFamily($token);
        }

        return ApiResponse::ok(null, __('api.auth.signed_out'));
    }

    public function me(Request $request): JsonResponse
    {
        $member = self::member($request);

        return ApiResponse::ok(['member_no' => $member->member_no, 'name' => $member->displayName(), 'status' => \App\Http\Api\ApiValue::enum($member->status)]);
    }
}
```

`LoginCodes::send` may throw `DomainRuleViolation` (unknown mobile, too soon); the renderer turns it into 422 with its message. Check `Member::displayName()` exists (used by `MemberStatementReport`).

Rate limiters in `AppServiceProvider::boot()`:

```php
        RateLimiter::for('member-login', fn (Request $request) => Limit::perMinute(5)->by(MobileNumber::normalize((string) $request->input('mobile')).'|'.$request->ip()));
        RateLimiter::for('member-refresh', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('member-api', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
```

(Imports: `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\RateLimiter`, `App\Support\Contact\MobileNumber`.)

`routes/api.php` — inside the `v1` group add:

```php
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:member-login');
    Route::post('auth/send-code', [AuthController::class, 'sendCode'])->middleware('throttle:member-login');
    Route::post('auth/refresh-token', [AuthController::class, 'refresh'])->middleware('throttle:member-refresh');

    Route::middleware(['auth:sanctum', 'abilities:member', 'member', 'throttle:member-api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });
```

Register the `member` alias in `bootstrap/app.php` (see Task 1). `CheckAbilities` throws `MissingAbilityException` (an `AuthorizationException`) for a refresh token used as access; the test expects **401**, so in `ApiExceptionRenderer` add an arm before `AuthorizationException`: `$e instanceof \Laravel\Sanctum\Exceptions\MissingAbilityException => ApiResponse::error(__('api.errors.unauthenticated'), 401),`.

`SetPortalPassword`: after `$user->forceFill(...)->save();` add `$user->tokens()->delete();`.

Lang (`api.auth.*`): `failed` ("The mobile number or password is not correct." / `'মোবাইল নম্বর বা পাসওয়ার্ড সঠিক নয়।'`), `codes_off`, `signed_in`, `signed_out`.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api tests/Feature/Portal tests/Feature/Notifications && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Member API: sign-in, SMS codes, rotating refresh tokens and logout"
```

---

### Task 3: Dashboard, profile and change password

**Files:**
- Create: `app/Http/Controllers/Api/Member/DashboardController.php`, `ProfileController.php`, `app/Http/Resources/Api/PaymentSummaryResource.php` (shared shape with Task 5), `app/Http/Requests/Api/ChangePasswordRequest.php`
- Modify: `routes/api.php`, `lang/{bn,en}/api.php`
- Test: `tests/Feature/Api/ApiDashboardProfileTest.php`

**Interfaces:**
- Consumes: `MemberSummary::for(Member): array{savings, advance, outstanding, paid_through, estimate, shares}`, `ChangeOwnPassword::__invoke(User, string $current, string $new)`, `ChangeOwnPassword::MIN_LENGTH`, `MemberTokens::revokeAll`, `ResolvesMember`.
- Produces: `PaymentSummaryResource::toArray()` → `{id, received_on, method: enum, trx_id, amount: money, status: enum}` (reused by Task 5 lists).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Members\Portal\MemberSummary;
use App\Domain\Members\Portal\PortalAccounts;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-15 10:00');
    $this->seed(ChartOfAccountsSeeder::class);
    app(\App\Domain\Accounting\Actions\OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(2, '2026-07', ['name_bn' => 'রহিম', 'name_en' => 'Rahim', 'nominees' => [['name' => 'Karima', 'relation' => 'Wife', 'share_percent' => '100']]]);
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    $this->token = memberToken($this->member);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('shows the member summary exactly as the portal computes it, with recent payments', function (): void {
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $this->member->id, 'method' => 'cash', 'amount' => '3000', 'received_on' => '2026-07-15']));
    app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment);
    $summary = app(MemberSummary::class)->for($this->member);

    $this->withToken($this->token)->withHeader('Accept-Language', 'en')->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.member.member_no', $this->member->member_no)
        ->assertJsonPath('data.member.name', 'Rahim')
        ->assertJsonPath('data.savings.poisha', $summary['savings']->poisha)
        ->assertJsonPath('data.advance.poisha', $summary['advance']->poisha)
        ->assertJsonPath('data.outstanding.poisha', $summary['outstanding']->poisha)
        ->assertJsonPath('data.paid_through', $summary['paid_through'] === null ? null : substr($summary['paid_through']->toDateString(), 0, 7))
        ->assertJsonPath('data.advance_months_estimate', $summary['estimate'])
        ->assertJsonPath('data.shares', 2)
        ->assertJsonPath('data.pay_now_visible', $summary['outstanding']->isPositive())
        ->assertJsonPath('data.recent_payments.0.amount.poisha', 300000)
        ->assertJsonPath('data.recent_payments.0.status.value', 'approved');
});

it('shows the profile with nominees, read-only', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.member_no', $this->member->member_no)
        ->assertJsonPath('data.name_bn', 'রহিম')
        ->assertJsonPath('data.mobile', $this->member->mobile)
        ->assertJsonPath('data.joined_on', '2026-07-01')
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.nominees.0.name', 'Karima')
        ->assertJsonPath('data.nominees.0.share_percent', '100.00');
});

it('changes the password and signs out other devices', function (): void {
    $user = app(PortalAccounts::class)->forMember($this->member);
    $user->forceFill(['password' => 'old-pass-1'])->save();
    $other = memberToken($this->member);

    $this->withToken($this->token)->postJson('/api/v1/profile/change-password', ['current_password' => 'wrong', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])
        ->assertStatus(422);

    $this->withToken($this->token)->postJson('/api/v1/profile/change-password', ['current_password' => 'old-pass-1', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])
        ->assertOk();

    expect(Hash::check('new-pass-2', $user->fresh()->password))->toBeTrue();
    app('auth')->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/profile')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/v1/profile')->assertOk();
});
```

Check `Role::Cashier` exists and that `PaymentData::fromForm` with `method => 'cash'` needs no `trx_id` (cash doesn't). If `nominees` in `memberData()` overrides need a different key, read `MemberData::fromForm` (it reads `nominees` rows with `name`, `relation`, `share_percent`).

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiDashboardProfileTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Implement**

`app/Http/Resources/Api/PaymentSummaryResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domain\Contributions\Models\Payment;
use App\Http\Api\ApiValue;

final class PaymentSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'received_on' => ApiValue::date($payment->received_on),
            'method' => ApiValue::enum($payment->method),
            'trx_id' => $payment->trx_id,
            'amount' => ApiValue::money($payment->amount_poisha),
            'status' => ApiValue::enum($payment->status),
        ];
    }
}
```

(Plain static mappers instead of `JsonResource` keep the envelope under `ApiResponse`'s control; use the same style for every resource in this plan.)

`DashboardController`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Portal\MemberSummary;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Resources\Api\PaymentSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController
{
    use ResolvesMember;

    public function summary(Request $request, MemberSummary $summaries): JsonResponse
    {
        $member = self::member($request);
        $summary = $summaries->for($member);

        return ApiResponse::ok([
            'member' => ['member_no' => $member->member_no, 'name' => $member->displayName(), 'status' => ApiValue::enum($member->status)],
            'savings' => ApiValue::money($summary['savings']),
            'advance' => ApiValue::money($summary['advance']),
            'outstanding' => ApiValue::money($summary['outstanding']),
            'paid_through' => ApiValue::month($summary['paid_through']),
            'advance_months_estimate' => $summary['estimate'],
            'shares' => $summary['shares'],
            'pay_now_visible' => $summary['outstanding']->isPositive(),
            'recent_payments' => Payment::query()->where('member_id', $member->id)->orderByDesc('received_on')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Payment $payment): array => PaymentSummaryResource::make($payment))->all(),
        ]);
    }
}
```

Check `RecentPaymentsWidget` for its exact ordering/limit and match it.

`ProfileController`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Models\Nominee;
use App\Domain\Members\Portal\ChangeOwnPassword;
use App\Domain\Members\Portal\MemberTokens;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Models\User;
use App\Support\Money\Bps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ProfileController
{
    use ResolvesMember;

    public function show(Request $request): JsonResponse
    {
        $member = self::member($request)->load('nominees');

        return ApiResponse::ok([
            'member_no' => $member->member_no,
            'name' => $member->displayName(),
            'name_bn' => $member->name_bn,
            'name_en' => $member->name_en,
            'guardian_name' => $member->guardian_name,
            'mobile' => $member->mobile,
            'email' => $member->email,
            'address' => $member->address,
            'nid' => $member->nid,
            'date_of_birth' => ApiValue::date($member->date_of_birth),
            'joined_on' => ApiValue::date($member->joined_on),
            'status' => ApiValue::enum($member->status),
            'nominees' => $member->nominees->map(fn (Nominee $nominee): array => [
                'name' => $nominee->name,
                'relation' => $nominee->relation,
                'mobile' => $nominee->mobile,
                'share_percent' => Bps::of($nominee->share_bps)->toPercentString(),
            ])->values()->all(),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request, ChangeOwnPassword $change, MemberTokens $tokens): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $change($user, (string) $request->string('current_password'), (string) $request->string('password'));

        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($query) => $query
            ->where('name', '!=', $current->name)
            ->where('name', '!=', 'refresh:'.substr($current->name, strlen('access:'))))->delete();

        return ApiResponse::ok(null, __('portal.profile.password_saved'));
    }
}
```

Match the profile fields to `resources/views/filament/member/profile.blade.php`: include only fields the portal shows (drop any the view doesn't display, e.g. `nid` if it isn't shown). `Bps` — check the method that renders `"100.00"` (`grep -n "public function" app/Support/Money/Bps.php`); if none, add `toPercentString(): string` returning `intdiv($this->value, 100).'.'.str_pad((string) ($this->value % 100), 2, '0', STR_PAD_LEFT)` with a unit test in `tests/Unit` matching the file that tests `Bps`. Check that `Member` has a `nominees()` relation. `ChangeOwnPassword` throws `DomainRuleViolation` for a wrong current password → 422 via the renderer.

`ChangePasswordRequest` rules: `current_password` required string; `password` required string `min:`.ChangeOwnPassword::MIN_LENGTH, `confirmed`.

Routes (inside the authenticated group):

```php
        Route::get('dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::post('profile/change-password', [ProfileController::class, 'changePassword']);
```

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Member API: dashboard summary, profile and password change"
```

---

### Task 4: Dues, dividends and SMS history

**Files:**
- Create: `app/Http/Controllers/Api/Member/DuesController.php`, `DividendsController.php`, `NotificationsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ApiListsTest.php`

**Interfaces:**
- Consumes: `ApiResponse::paginated`, `ApiValue`, `ResolvesMember`, models `Due`, `DividendLine`, `SmsMessage`, enums `DueStatus`, `DueType`, `SmsTemplateKey`.
- Produces: `GET dues?status=&type=&page=`, `GET dividends?page=`, `GET notifications?page=`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-05 10:00');
    $this->seed(ChartOfAccountsSeeder::class);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->other = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-08'));
    $this->token = memberToken($this->member);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists only this member\'s open dues by default, newest month first, paginated', function (): void {
    $response = $this->withToken($this->token)->getJson('/api/v1/dues')->assertOk();

    expect(collect($response->json('data'))->pluck('month')->unique()->values()->all())->toBe(['2026-08', '2026-07', ])
        ->and(collect($response->json('data'))->pluck('status.value')->unique()->all())->toBe(['open'])
        ->and($response->json('meta'))->toMatchArray(['current_page' => 1, 'per_page' => 20])
        ->and($response->json('data.0'))->toHaveKeys(['id', 'month', 'type', 'amount', 'paid', 'outstanding', 'due_date', 'status']);

    $ids = \App\Domain\Contributions\Models\Due::query()->where('member_id', $this->other->id)->pluck('id')->all();
    expect(collect($response->json('data'))->pluck('id')->intersect($ids)->all())->toBe([]);
});

it('filters dues by type and by any status', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/dues?type=registration&status=all')->assertOk()
        ->assertJsonPath('data.0.type.value', 'registration');

    $this->withToken($this->token)->getJson('/api/v1/dues?status=bogus')->assertStatus(422);
});

it('lists dividends and SMS history, never login codes', function (): void {
    SmsMessage::query()->create(['to' => $this->member->mobile, 'member_id' => $this->member->id, 'template_key' => 'login_code', 'body' => 'code 123456', 'segments' => 1, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);
    SmsMessage::query()->create(['to' => $this->member->mobile, 'member_id' => $this->member->id, 'template_key' => 'dues_generated', 'body' => 'Your dues', 'segments' => 1, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);
    SmsMessage::query()->create(['to' => $this->other->mobile, 'member_id' => $this->other->id, 'template_key' => 'dues_generated', 'body' => 'Other', 'segments' => 1, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);

    $bodies = collect($this->withToken($this->token)->getJson('/api/v1/notifications')->assertOk()->json('data'))->pluck('body');
    expect($bodies->all())->toContain('Your dues')->not->toContain('code 123456')->not->toContain('Other');

    $this->withToken($this->token)->getJson('/api/v1/dividends')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});
```

Check `SmsMessage` required columns/casts (`status` is `SmsStatus`; pass the enum if the cast rejects strings) and whether onboarding already sends a welcome SMS (then the list contains it too — that's fine).

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiListsTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Implement**

`DuesController`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DuesController
{
    use ResolvesMember;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...array_column(DueStatus::cases(), 'value')])],
            'type' => ['nullable', Rule::enum(DueType::class)],
        ]);
        $status = $filters['status'] ?? DueStatus::Open->value;

        $page = Due::query()
            ->where('member_id', self::member($request)->id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->orderByDesc('month')->orderBy('id')
            ->paginate(ApiResponse::PER_PAGE);

        return ApiResponse::paginated($page, fn (Due $due): array => [
            'id' => $due->id,
            'month' => ApiValue::month($due->month),
            'type' => ApiValue::enum($due->type),
            'amount' => ApiValue::money($due->amount_poisha),
            'paid' => ApiValue::money($due->paid_poisha),
            'outstanding' => ApiValue::money($due->outstanding_poisha),
            'due_date' => ApiValue::date($due->due_date),
            'status' => ApiValue::enum($due->status),
        ]);
    }
}
```

`DividendsController::index` — `DividendLine::query()->where('member_id', …)->with('yearEnd.fiscalYear')->orderByDesc('id')->paginate(20)` mapped to `{id, fiscal_year: $line->yearEnd->fiscalYear->code, share_months, amount: money, status: enum, settled_via, settled_at: ApiValue::time()}`.

`NotificationsController::index` — `SmsMessage::query()->where('member_id', …)->where(fn ($q) => $q->whereNull('template_key')->orWhere('template_key', '!=', SmsTemplateKey::LoginCode->value))->orderByDesc('id')->paginate(20)` mapped to `{id, kind: template_key === null ? null : ApiValue::enum(SmsTemplateKey::tryFrom(...)), body, status: enum, sent_at: time, created_at: time}`. Check that `SmsTemplateKey` implements `HasLabel`; if not, `ApiValue::enum` falls back to the value as label — acceptable, but prefer adding `HasLabel` with existing labels if `lang/*/sms.php` already names templates.

Routes (authenticated group): `Route::get('dues', …)`, `Route::get('dividends', …)`, `Route::get('notifications', …)`.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Member API: dues, dividends and SMS history"
```

---

### Task 5: Payments — list, detail, receipt, pay online

**Files:**
- Create: `app/Http/Controllers/Api/Member/PaymentsController.php`, `app/Http/Requests/Api/SubmitPaymentRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ApiPaymentsTest.php`

**Interfaces:**
- Consumes: `PaymentSummaryResource::make`, `RecordPayment`, `PaymentData::fromForm`, `ReceiptDocument::signedUrl(Payment): string`, `PaymentAllocation` (`due` relation), `AdvanceLedgerEntry` (`payment_id`, kind `payment_surplus`).
- Produces: `GET payments?status=`, `GET payments/{payment}`, `GET payments/{payment}/receipt`, `POST payments` (multipart).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Models\Payment;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-15 10:00');
    Storage::fake('local');
    $this->seed(ChartOfAccountsSeeder::class);
    app(\App\Domain\Accounting\Actions\OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->other = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    $this->token = memberToken($this->member);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function approvedPaymentFor(\App\Domain\Members\Models\Member $member, string $amount): Payment
{
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $member->id, 'method' => 'cash', 'amount' => $amount, 'received_on' => '2026-07-15']));

    return app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment);
}

it('lists and shows the member\'s own payments with how they were allocated', function (): void {
    $payment = approvedPaymentFor($this->member, '3000');

    $this->withToken($this->token)->getJson('/api/v1/payments')->assertOk()->assertJsonPath('data.0.id', $payment->id)->assertJsonPath('meta.total', 1);

    $detail = $this->withToken($this->token)->getJson("/api/v1/payments/{$payment->id}")->assertOk()->json('data');
    $allocated = collect($detail['allocations'])->sum('amount.poisha');

    expect($detail['amount']['poisha'])->toBe(300000)
        ->and($allocated + $detail['to_advance']['poisha'])->toBe(300000)
        ->and($detail['allocations'][0])->toHaveKeys(['due_id', 'month', 'type', 'amount']);
});

it('never shows another member\'s payment or receipt', function (): void {
    $theirs = approvedPaymentFor($this->other, '500');

    $this->withToken($this->token)->getJson("/api/v1/payments/{$theirs->id}")->assertNotFound()->assertJsonPath('data', null);
    $this->withToken($this->token)->getJson("/api/v1/payments/{$theirs->id}/receipt")->assertNotFound();
});

it('gives a receipt link for approved payments only', function (): void {
    $approved = approvedPaymentFor($this->member, '500');
    $pending = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $this->member->id, 'method' => 'cash', 'amount' => '100', 'received_on' => '2026-07-15']));

    $url = $this->withToken($this->token)->getJson("/api/v1/payments/{$approved->id}/receipt")->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withToken($this->token)->getJson("/api/v1/payments/{$pending->id}/receipt")->assertNotFound();
});

it('submits a bKash payment with proof as pending, exactly once per key', function (): void {
    $key = (string) Str::uuid();
    $body = fn (string $amount) => ['method' => 'bkash', 'amount' => $amount, 'trx_id' => 'abc12345x', 'received_on' => '2026-07-15', 'idempotency_key' => $key, 'proof' => UploadedFile::fake()->image('proof.jpg')];

    $first = $this->withToken($this->token)->post('/api/v1/payments', $body('১,০০০.৫০'), ['Accept' => 'application/json'])->assertCreated()->json('data');
    $again = $this->withToken($this->token)->post('/api/v1/payments', $body('1000.50'), ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($first['id'])->toBe($again['id'])
        ->and($first['status']['value'])->toBe('pending')
        ->and($first['amount']['poisha'])->toBe(100050)
        ->and($first['trx_id'])->toBe('ABC12345X')
        ->and(Payment::query()->where('member_id', $this->member->id)->count())->toBe(1)
        ->and(Storage::disk('local')->files('payment-proofs'))->toHaveCount(1);

    $this->withToken($this->token)->post('/api/v1/payments', $body('999'), ['Accept' => 'application/json'])->assertStatus(409);
    expect(Storage::disk('local')->files('payment-proofs'))->toHaveCount(1);
});

it('validates the payment form', function (array $override, string $field): void {
    $body = ['method' => 'bkash', 'amount' => '500', 'trx_id' => 'ABC12345', 'received_on' => '2026-07-15', 'idempotency_key' => (string) Str::uuid(), 'proof' => UploadedFile::fake()->image('p.jpg'), ...$override];

    $this->withToken($this->token)->post('/api/v1/payments', $body, ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
})->with([
    'cash not allowed' => [['method' => 'cash'], 'method'],
    'three decimals' => [['amount' => '10.555'], 'amount'],
    'trx format' => [['trx_id' => 'ab'], 'trx_id'],
    'missing proof' => [['proof' => null], 'proof'],
    'wrong file type' => [['proof' => UploadedFile::fake()->create('p.exe', 10)], 'proof'],
    'not a uuid' => [['idempotency_key' => 'x'], 'idempotency_key'],
]);
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiPaymentsTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Implement**

`SubmitPaymentRequest`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Support\Money\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The portal's Pay Online form, field for field (Filament/Member/Pages/PayOnline).
 */
final class SubmitPaymentRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:bkash,nagad'],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $money = Money::tryOfTaka((string) $value);
                if ($money === null || ! $money->isPositive()) {
                    $fail(__('api.errors.amount'));
                }
            }],
            'trx_id' => ['required', 'regex:/^[A-Za-z0-9]{6,40}$/'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'proof' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:'.(int) config('somiti.max_proof_kb')],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
```

`PaymentsController`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Requests\Api\SubmitPaymentRequest;
use App\Http\Resources\Api\PaymentSummaryResource;
use App\Models\User;
use App\Reports\ReceiptDocument;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

final class PaymentsController
{
    use ResolvesMember;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(PaymentStatus::class)]]);

        $page = Payment::query()
            ->where('member_id', self::member($request)->id)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('received_on')->orderByDesc('id')
            ->paginate(ApiResponse::PER_PAGE);

        return ApiResponse::paginated($page, fn (Payment $payment): array => PaymentSummaryResource::make($payment));
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        return ApiResponse::ok($this->detail($this->own($request, $payment)));
    }

    public function receipt(Request $request, int $payment): JsonResponse
    {
        $record = $this->own($request, $payment);
        abort_unless($record->status === PaymentStatus::Approved, 404);

        return ApiResponse::ok(['url' => ReceiptDocument::signedUrl($record)]);
    }

    public function store(SubmitPaymentRequest $request, RecordPayment $record): JsonResponse
    {
        $member = self::member($request);
        $existing = Payment::query()->where('idempotency_key', $request->string('idempotency_key'))->first();
        $path = $existing?->proof_path;

        if ($existing === null) {
            $path = $request->file('proof')?->store('payment-proofs', 'local');
        }

        try {
            /** @var User $actor */
            $actor = $request->user();
            $payment = $record($actor, PaymentData::fromForm([
                'member_id' => $member->id,
                'method' => (string) $request->string('method'),
                'amount' => (string) $request->string('amount'),
                'trx_id' => strtoupper((string) $request->string('trx_id')),
                'received_on' => (string) $request->string('received_on'),
                'idempotency_key' => (string) $request->string('idempotency_key'),
                'proof_path' => $path,
            ]));
        } catch (Throwable $e) {
            if ($existing === null && is_string($path)) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        return ApiResponse::ok($this->detail($payment), __('portal.submit.done', ['amount' => \App\Filament\Support\Display::money($payment->amount_poisha)]), 201);
    }

    private function own(Request $request, int $id): Payment
    {
        return Payment::query()->where('member_id', self::member($request)->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Payment $payment): array
    {
        $allocations = PaymentAllocation::query()->where('payment_id', $payment->id)->with('due')->orderBy('id')->get();
        $toAdvance = Money::ofPoisha((int) AdvanceLedgerEntry::query()
            ->where('payment_id', $payment->id)->where('kind', AdvanceEntryKind::PaymentSurplus)->sum('delta_poisha'));

        return [
            ...PaymentSummaryResource::make($payment),
            'rejection_reason' => $payment->rejection_reason,
            'approved_at' => ApiValue::time($payment->approved_at),
            'receipt_available' => $payment->status === PaymentStatus::Approved,
            'allocations' => $allocations->map(fn (PaymentAllocation $allocation): array => [
                'due_id' => $allocation->due_id,
                'month' => ApiValue::month($allocation->due->month),
                'type' => ApiValue::enum($allocation->due->type),
                'amount' => ApiValue::money($allocation->amount_poisha),
            ])->all(),
            'to_advance' => ApiValue::money($toAdvance),
        ];
    }
}
```

Notes:
- If an idempotent retry arrives for a key that belongs to **another** member's payment, `RecordPayment` throws the conflict (409) — correct.
- `RecordPayment` authorizes `create` on `Payment` for the member user (the portal relies on the same) and enforces member-submission rules (`assertMemberSubmission`).
- Before writing the `to_advance` query, check how `ApprovePayment` records the surplus (`grep -n "PaymentSurplus" app/Domain/Contributions/Actions/ApprovePayment.php`) and match the columns.
- Lang: `api.errors.amount` ("Enter an amount greater than zero with up to 2 decimals." / bn).

Routes (authenticated group):

```php
        Route::get('payments', [PaymentsController::class, 'index']);
        Route::post('payments', [PaymentsController::class, 'store']);
        Route::get('payments/{payment}', [PaymentsController::class, 'show'])->whereNumber('payment');
        Route::get('payments/{payment}/receipt', [PaymentsController::class, 'receipt'])->whereNumber('payment');
```

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Member API: payments, receipts and pay online"
```

---

### Task 6: Statement and statement PDF

**Files:**
- Create: `app/Http/Controllers/Api/Member/StatementController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ApiStatementTest.php`

**Interfaces:**
- Consumes: `MemberStatementReport::defaults()`, `::data(array{member: int, from: ?string, until: ?string}): ?array{opening: Money, rows: list<array{date, description, charge: Money|null, paid: Money|null, balance: Money}>, total_charges: Money, total_paid: Money, closing: Money, ...}`, `ReportExporter::pdf(Report, array): ?string`, `MemberStatementReport::filename(array): string`.
- Produces: `GET statement?from=&until=`, `GET statement/pdf?from=&until=`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Enums\Role;
use App\Reports\Definitions\MemberStatementReport;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-20 10:00');
    $this->seed(ChartOfAccountsSeeder::class);
    app(\App\Domain\Accounting\Actions\OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $this->member->id, 'method' => 'cash', 'amount' => '700', 'received_on' => '2026-07-15']));
    app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment);
    $this->token = memberToken($this->member);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('returns the same statement as the portal for the member, ignoring any member parameter', function (): void {
    $expected = app(MemberStatementReport::class)->data(['member' => $this->member->id, 'from' => '2026-07-01', 'until' => '2026-07-31']);
    $other = onboard(1, '2026-07');

    $data = $this->withToken($this->token)->getJson("/api/v1/statement?from=2026-07-01&until=2026-07-31&member={$other->id}")->assertOk()->json('data');

    expect($data['opening']['poisha'])->toBe($expected['opening']->poisha)
        ->and($data['closing']['poisha'])->toBe($expected['closing']->poisha)
        ->and($data['total_charges']['poisha'])->toBe($expected['total_charges']->poisha)
        ->and($data['total_paid']['poisha'])->toBe($expected['total_paid']->poisha)
        ->and(count($data['rows']))->toBe(count($expected['rows']))
        ->and($data['rows'][0])->toHaveKeys(['date', 'description', 'charge', 'paid', 'balance'])
        ->and($data['from'])->toBe('2026-07-01');
});

it('downloads the statement PDF', function (): void {
    $this->withToken($this->token)->get('/api/v1/statement/pdf?from=2026-07-01&until=2026-07-31')
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('rejects a reversed date range', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/statement?from=2026-07-31&until=2026-07-01')->assertStatus(422);
});
```

Check the real keys of `MemberReports::statement()` rows (`grep -n "rows\[\] =" app/Reports/MemberReports.php` or wherever it lives) — the plan assumes `date`, `description`, `charge`, `paid`, `balance`, with `charge`/`paid` possibly null or zero Money.

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiStatementTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Reports\Definitions\MemberStatementReport;
use App\Reports\ReportExporter;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's statement (Filament/Member/Pages/Statement) with the member fixed to the token's.
 */
final class StatementController
{
    use ResolvesMember;

    public function __construct(private readonly MemberStatementReport $report) {}

    public function show(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $data = $this->report->data($filters) ?? abort(404);

        return ApiResponse::ok([
            'from' => $filters['from'],
            'until' => $filters['until'],
            'opening' => ApiValue::money($data['opening']),
            'rows' => array_map(fn (array $row): array => [
                'date' => ApiValue::date($row['date']),
                'description' => (string) $row['description'],
                'charge' => $row['charge'] instanceof Money ? ApiValue::money($row['charge']) : null,
                'paid' => $row['paid'] instanceof Money ? ApiValue::money($row['paid']) : null,
                'balance' => ApiValue::money($row['balance']),
            ], $data['rows']),
            'total_charges' => ApiValue::money($data['total_charges']),
            'total_paid' => ApiValue::money($data['total_paid']),
            'closing' => ApiValue::money($data['closing']),
        ]);
    }

    public function pdf(Request $request, ReportExporter $exporter): Response
    {
        $filters = $this->filters($request);
        $content = $exporter->pdf($this->report, $filters) ?? abort(404);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->report->filename($filters).'.pdf"',
        ]);
    }

    /**
     * @return array{member: int, from: string, until: string}
     */
    private function filters(Request $request): array
    {
        $valid = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $defaults = $this->report->defaults();

        return [
            'member' => self::member($request)->id,
            'from' => (string) ($valid['from'] ?? $defaults['from']),
            'until' => (string) ($valid['until'] ?? $defaults['until']),
        ];
    }
}
```

`defaults()` reads `request()->integer('member')` — harmless here because `filters()` always overwrites `member`. If `defaults()` returns dates as objects rather than strings, format them with `->toDateString()`.

Routes: `Route::get('statement', [StatementController::class, 'show']);` and `Route::get('statement/pdf', [StatementController::class, 'pdf']);`.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Member API: statement and statement PDF"
```

---

### Task 7: Shares overview

**Files:**
- Create: `app/Http/Controllers/Api/Member/SharesController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/ApiSharesTest.php`

**Interfaces:**
- Consumes: `Member::sharesIn(YearMonth): int`, `ShareTransaction`, `RateResolver::find(YearMonth): ?RatePlan`, `RatePlan` fields (`share_unit_poisha`, `service_charge_per_share_poisha`, `registration_fee_per_share_poisha`, `due_day`, `grace_days`, `late_fee_mode`, `late_fee_fixed_poisha`, `late_fee_bps`, `late_fee_cap_poisha`, `late_fee_frequency`, `effective_from`), `Bps::of(int)`.
- Produces: `GET shares/overview`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Members\Actions\ChangeShares;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-05 10:00');
    approvedPlan('2026-07', '500');
    approvedPlan('2026-10', '600');
    $this->member = onboard(2, '2026-07');
    $this->token = memberToken($this->member);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('shows current shares, their history and this month\'s rates from the approved plan', function (): void {
    app(ChangeShares::class)(userWithRole(Role::Secretary), $this->member, 3, YearMonth::parse('2026-11'), 'Bought one more');

    $data = $this->withToken($this->token)->getJson('/api/v1/shares/overview')->assertOk()->json('data');

    expect($data['current_shares'])->toBe(2)
        ->and($data['history'][0])->toMatchArray(['shares_after' => 3, 'effective_from' => '2026-11'])
        ->and($data['history'][1])->toMatchArray(['shares_after' => 2, 'effective_from' => '2026-07'])
        ->and($data['rates']['effective_from'])->toBe('2026-10')
        ->and($data['rates']['share_unit']['poisha'])->toBe(60000)
        ->and($data['rates'])->toHaveKeys(['service_charge_per_share', 'registration_fee_per_share', 'due_day', 'grace_days', 'late_fee']);
});

it('shows no rates when no plan covers this month', function (): void {
    CarbonImmutable::setTestNow('2026-06-05 10:00');

    $this->withToken($this->token)->getJson('/api/v1/shares/overview')->assertOk()->assertJsonPath('data.rates', null);
});
```

Check `ChangeShares`'s signature (`grep -n "function __invoke" app/Domain/Members/Actions/ChangeShares.php`) — it may take the new total or a delta; adjust the call so the member ends with 3 shares from 2026-11.

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Api/ApiSharesTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Models\ShareTransaction;
use App\Domain\Settings\Services\RateResolver;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SharesController
{
    use ResolvesMember;

    public function overview(Request $request, RateResolver $rates): JsonResponse
    {
        $member = self::member($request);
        $month = YearMonth::current();
        $plan = $rates->find($month);

        return ApiResponse::ok([
            'current_shares' => $member->sharesIn($month),
            'history' => ShareTransaction::query()->where('member_id', $member->id)->orderByDesc('effective_from')->orderByDesc('id')->get()
                ->map(fn (ShareTransaction $change): array => [
                    'type' => ApiValue::enum($change->type),
                    'shares' => $change->shares,
                    'shares_after' => $change->shares_after,
                    'effective_from' => ApiValue::month($change->effective_from),
                    'reason' => $change->reason,
                ])->all(),
            'rates' => $plan === null ? null : [
                'effective_from' => ApiValue::month($plan->effective_from),
                'share_unit' => ApiValue::money($plan->share_unit_poisha),
                'service_charge_per_share' => ApiValue::money($plan->service_charge_per_share_poisha),
                'registration_fee_per_share' => ApiValue::money($plan->registration_fee_per_share_poisha),
                'due_day' => $plan->due_day,
                'grace_days' => $plan->grace_days,
                'late_fee' => [
                    'mode' => ApiValue::enum($plan->late_fee_mode),
                    'fixed' => ApiValue::money($plan->late_fee_fixed_poisha),
                    'percent' => $plan->late_fee_bps === null ? null : Bps::of($plan->late_fee_bps)->toPercentString(),
                    'cap' => ApiValue::money($plan->late_fee_cap_poisha),
                    'frequency' => ApiValue::enum($plan->late_fee_frequency),
                ],
            ],
        ]);
    }
}
```

`toPercentString()` comes from Task 3. `YearMonth::current()` uses Asia/Dhaka and respects `CarbonImmutable::setTestNow`.

Route: `Route::get('shares/overview', [SharesController::class, 'overview']);`.

- [ ] **Step 4: Run tests, format, analyse**

Run: `php artisan test --compact tests/Feature/Api && vendor/bin/pint --dirty --format agent && composer analyse`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Member API: shares overview with current rates"
```

---

### Task 8: Member-isolation sweep, staff refusal and the full gate

**Files:**
- Test: `tests/Feature/Api/ApiIsolationTest.php`

**Interfaces:**
- Consumes: every route above.

- [ ] **Step 1: Write the sweep test**

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Support\Facades\Route;

/*
| Every authenticated API route refuses no token, a staff account and a refresh token, and no list
| leaks another member's rows. New routes are covered automatically.
*/

beforeEach(function (): void {
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
});

function memberApiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && in_array('member', $route->gatherMiddleware(), true) && in_array('GET', $route->methods(), true))
        ->map(fn ($route) => '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()))
        ->values()->all();
}

it('has the authenticated routes the app needs', function (): void {
    expect(memberApiRoutes())->toContain('/api/v1/dashboard/summary', '/api/v1/dues', '/api/v1/payments', '/api/v1/statement', '/api/v1/dividends', '/api/v1/shares/overview', '/api/v1/profile', '/api/v1/notifications');
});

it('refuses every member route without a member access token', function (): void {
    $staff = userWithRole(Role::Accountant);
    $staffToken = $staff->createToken('access:x', ['member'], now()->addHour())->plainTextToken;

    foreach (memberApiRoutes() as $uri) {
        app('auth')->forgetGuards();
        $this->getJson($uri)->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($staffToken)->getJson($uri)->assertForbidden();
    }
});
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/Api/ApiIsolationTest.php`
Expected: PASS (if a route returns something else, fix that route's middleware — it must sit in the authenticated group).

- [ ] **Step 3: Full gate**

Run: `composer test && composer analyse && composer format:check`
Expected: all green. Then `php artisan route:list --path=api` and paste the list into the commit message body for the plan-2 author.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "Member API: isolation sweep across every member route"
```
