# Production 500s (128M OOM), Deployment Sequence & Error Observability

* **Author:** Rabbi
* **Date:** 2026-11-15

## Overview

Production web requests on `shomiti.techrealify.com` are failing with HTTP 500 errors (`FatalError: Allowed memory size of 134217728 bytes exhausted` at `vendor/nesbot/carbon/src/Carbon/AbstractTranslator.php:423`) while displaying an error reference (such as `NS4ERKGG`) that cannot be found in `storage/logs/laravel.log`.

The root cause is a combined runtime, deployment, and observability failure:

1. **128 MB PHP memory ceiling:** PHP-FPM is configured with `memory_limit = 128M`. A Laravel + Filament + Livewire + Carbon (`bn`/`en`) request reaches ~125.5 MB and crashes when Carbon loads a locale file.
2. **Self-defeating deploy commands:** The current deploy sequence runs `php artisan optimize` and immediately undoes it with `php artisan optimize:clear`, followed by `php artisan config:cache` (leaving routes, views, events, and Filament components/icons uncached). It also omits `composer install --no-dev --optimize-autoloader` and `npm run build`, and launches `php artisan queue:work` directly without `php artisan queue:restart` or a process supervisor.
3. **Blind error-reference logging on OOM:** `AssignErrorReference` sets `error_ref` in Laravel `Context`, and `resources/views/errors/layout.blade.php` displays that code to the user. When PHP hits an out-of-memory `FatalError`, Monolog has zero memory headroom left to serialize the exception and `Context` to `laravel.log`, leaving support with a reference code that does not exist in the logs.

## Analysis

### 1. Memory Exhaustion at `Carbon\AbstractTranslator.php:423`
`AbstractTranslator::loadFile()` executes `include $file` for a Carbon locale file. The locale file itself is small; it is simply the allocation that crosses the 128 MB (`134217728` bytes) limit when PHP asks for the next 2.5 MB (`2621440` bytes) chunk. Contributing factors in this codebase (`AppServiceProvider::boot()`) include:
- `Date::use(CarbonImmutable::class)` and bilingual `LanguageSwitch` (`bn`/`en`).
- Filament `Table` defaults (`paginated([10, 25, 50, 100])`, `defaultPaginationPageOption(25)`), plus heavy dependencies (`filament/filament`, `mpdf/mpdf`, `maatwebsite/excel`, `spatie/laravel-backup`).
- `Model::preventLazyLoading(! $this->app->isProduction())`, which disables lazy-loading checks in production so N+1 queries can silently hydrate large Eloquent/Carbon object graphs.
- Wiping all route, view, event, and Filament caches on every deploy via `optimize:clear`, forcing PHP to recompile and discover them in request memory.

### 2. Why `NS4ERKGG` Was Missing from `laravel.log`
`AssignErrorReference` (`bootstrap/app.php`) calls `ErrorReference::current()`, which stores `error_ref` in Laravel `Context`. On a normal exception, Monolog writes `Context` alongside the stack trace. On an OOM `FatalError`, PHP's shutdown handler runs with no free memory, so Monolog either fails to allocate memory to write the record or PHP-FPM logs only to its own `error_log` without Laravel `Context`. Meanwhile, `resources/views/errors/layout.blade.php` still runs `ErrorReference::current()` and displays the unlogged code to the user.

### 3. Deployment & Queue Lifecycle Risks
- Running `php artisan down` / `up` around `git pull --rebase` and `migrate --force` risks leaving the site stuck in maintenance mode if any step fails.
- Running `php artisan queue:work` at the end of a deploy blocks the SSH session and does not restart existing background workers (`php artisan queue:restart`), risking stale code or duplicate workers against scheduled financial/backup jobs in `routes/console.php`.
- Running `artisan` commands as a user different from PHP-FPM (`techreal` vs `www-data`) can create root/deploy-user-owned files under `storage/`, causing permission-denied 500s.

### 4. Key Trade-Offs & Resolutions
- **Logging `error_ref` when memory is 0:** Pre-allocate a small emergency memory buffer (for example, 512 KB) during bootstrap; on a fatal shutdown (`register_shutdown_function` + `error_get_last()`), free that buffer and write a minimal single-line record (`error_ref`, message, file, line, URL) directly to the log file before rendering `errors/layout.blade.php`.
- **Catching N+1 memory bloat without crashing users:** Keep `preventLazyLoading` throwing in local/testing, and attach `Model::handleLazyLoadingViolationUsing()` in production to log a warning with `error_ref` instead of throwing.
- **Preventing stale caches without causing uncached OOMs:** Clear caches *before* rebuilding (if needed), then run `optimize` and `filament:optimize`, followed by a PHP-FPM reload and `queue:restart`.

## Goals vs. Non-Goals

* **In-Scope Goals:**
  * Diagnose and stop the 128 MB OOM 500s on production web requests.
  * Fix the deployment command sequence (dependencies, assets, cache warming, PHP-FPM reload, `queue:restart`, and supervisor-managed `queue:work`).
  * Guarantee `error_ref` is written to logs even during PHP `FatalError` / OOM shutdowns.
  * Add non-destructive N+1 lazy-loading warning logs in production so memory-heavy pages are surfaced.
* **Out-of-Scope Non-Goals:**
  * Rewriting Filament panels or replacing `mpdf`/`maatwebsite/excel`.
  * Moving hosting providers or introducing external APM/SaaS error trackers unless requested later.

## Alternatives Weighed

* **Raising `memory_limit` alone without fixing deploy/caching or N+1 telemetry:** Rejected as incomplete — raising PHP-FPM `memory_limit` to `256M`/`512M` stops immediate OOMs on normal pages, but leaving `optimize:clear` after `optimize` and silent N+1s in place wastes memory and hides real leaks.
* **Enabling `Model::preventLazyLoading(true)` in production:** Rejected — throwing `LazyLoadingViolationException` on any un-eager-loaded relation in production would turn minor N+1s into user-facing 500s. Logging violations via `handleLazyLoadingViolationUsing()` gives visibility without downtime.
* **Leaving `queue:work` in the deploy script:** Rejected — a deploy script must exit cleanly; long-running workers belong under `systemd` or `supervisor` with `php artisan queue:restart` during deploy.

## Key Decisions & Staging for Next Steps

1. **Server PHP-FPM & OPcache:** Raise PHP-FPM `memory_limit` (at least `256M`, or `512M` for PDF/Excel exports) and verify OPcache is enabled and reloaded on deploy.
2. **Deploy Script Overhaul:** Replace the current commands with: `git pull` -> `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` -> `npm ci && npm run build` -> `migrate --force` -> `optimize` + `filament:optimize` -> `queue:restart` -> PHP-FPM reload.
3. **OOM-Safe `error_ref` & N+1 Telemetry:** Implement a reserved-memory fatal shutdown logger that records `error_ref`, and add production lazy-loading warning logs in `AppServiceProvider`.
