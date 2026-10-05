<?php

declare(strict_types=1);

use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

// The site opens on the staff panel; its sign-in page links to the member portal (/portal).
Route::redirect('/', '/admin')->name('home');

Route::get('/receipts/{payment}', ReceiptController::class)
    ->middleware('signed')
    ->name('receipts.show');

// Public: linked from both sign-in pages and given to the Play Store as the app's privacy policy.
Route::get('/privacy-policy', PrivacyPolicyController::class)->name('privacy');
