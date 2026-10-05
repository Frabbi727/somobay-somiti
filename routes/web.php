<?php

declare(strict_types=1);

use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

// The site opens on the staff panel; its sign-in page links to the member portal (/portal).
Route::redirect('/', '/admin')->name('home');

Route::get('/receipts/{payment}', ReceiptController::class)
    ->middleware('signed')
    ->name('receipts.show');
