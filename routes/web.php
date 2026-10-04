<?php

declare(strict_types=1);

use App\Http\Controllers\PortalSessionController;
use App\Http\Controllers\ReceiptController;
use App\Http\Middleware\EnsurePortalMember;
use App\Livewire\Portal;
use Illuminate\Support\Facades\Route;

// Home: choose the member portal or the staff panel.
Route::view('/', 'home')->name('home');

Route::get('/receipts/{payment}', ReceiptController::class)
    ->middleware('signed')
    ->name('receipts.show');

/*
|--------------------------------------------------------------------------
| Member portal (SOMITI_SPEC.md P6.S1)
|--------------------------------------------------------------------------
*/

Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::livewire('/login', Portal\Login::class)->name('login');
    Route::post('/locale/{locale}', [PortalSessionController::class, 'locale'])->name('locale');

    Route::middleware(['auth', EnsurePortalMember::class])->group(function (): void {
        Route::livewire('/', Portal\Dashboard::class)->name('dashboard');
        Route::livewire('/dues', Portal\Dues::class)->name('dues');
        Route::livewire('/receipts', Portal\Receipts::class)->name('receipts');
        Route::livewire('/pay', Portal\SubmitPayment::class)->name('submit');
        Route::livewire('/profile', Portal\Profile::class)->name('profile');
        Route::post('/logout', [PortalSessionController::class, 'logout'])->name('logout');
    });
});
