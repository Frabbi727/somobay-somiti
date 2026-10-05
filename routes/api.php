<?php

declare(strict_types=1);

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\Member\AuthController;
use App\Http\Controllers\Api\Member\ConfigController;
use App\Http\Controllers\Api\Member\DashboardController;
use App\Http\Controllers\Api\Member\DividendsController;
use App\Http\Controllers\Api\Member\DuesController;
use App\Http\Controllers\Api\Member\NotificationsController;
use App\Http\Controllers\Api\Member\PaymentsController;
use App\Http\Controllers\Api\Member\ProfileController;
use App\Http\Controllers\Api\Member\SharesController;
use App\Http\Controllers\Api\Member\StatementController;
use Illuminate\Support\Facades\Route;

/*
| The member app API (docs/superpowers/specs/2026-10-05-member-mobile-api-design.md). Every route
| answers in the app's envelope; the member always comes from the token, never from input.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('config/somiti-info', [ConfigController::class, 'somitiInfo']);
    Route::get('config/logo', [ConfigController::class, 'logo'])->middleware('signed')->name('api.logo');

    // Token-free by design: opened in the phone's browser, protected by the signature.
    Route::get('statement/pdf-signed', [StatementController::class, 'pdfSigned'])->middleware('signed')->name('api.statement.signed');

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:member-login');
    Route::post('auth/send-code', [AuthController::class, 'sendCode'])->middleware('throttle:member-login');
    Route::post('auth/refresh-token', [AuthController::class, 'refresh'])->middleware('throttle:member-refresh');

    Route::middleware(['auth:sanctum', 'abilities:member', 'member', 'throttle:member-api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('profile', [ProfileController::class, 'show']);
        Route::post('profile/change-password', [ProfileController::class, 'changePassword']);
        Route::get('dues', [DuesController::class, 'index']);
        Route::get('dividends', [DividendsController::class, 'index']);
        Route::get('notifications', [NotificationsController::class, 'index']);
        Route::get('payments', [PaymentsController::class, 'index']);
        Route::post('payments', [PaymentsController::class, 'store']);
        Route::get('payments/{payment}', [PaymentsController::class, 'show'])->whereNumber('payment');
        Route::get('payments/{payment}/receipt', [PaymentsController::class, 'receipt'])->whereNumber('payment');
        Route::get('statement', [StatementController::class, 'show']);
        Route::get('statement/pdf', [StatementController::class, 'pdf']);
        Route::get('statement/pdf-link', [StatementController::class, 'pdfLink']);
        Route::get('shares/overview', [SharesController::class, 'overview']);
    });
});

Route::fallback(fn () => ApiResponse::error(__('api.errors.not_found'), 404));
