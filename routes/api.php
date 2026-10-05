<?php

declare(strict_types=1);

use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\Member\ConfigController;
use Illuminate\Support\Facades\Route;

/*
| The member app API (docs/superpowers/specs/2026-10-05-member-mobile-api-design.md). Every route
| answers in the app's envelope; the member always comes from the token, never from input.
*/

Route::prefix('v1')->middleware('api.locale')->group(function (): void {
    Route::get('config/somiti-info', [ConfigController::class, 'somitiInfo']);
    Route::get('config/logo', [ConfigController::class, 'logo'])->middleware('signed')->name('api.logo');
});

Route::fallback(fn () => ApiResponse::error(__('api.errors.not_found'), 404));
