<?php

declare(strict_types=1);

use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/receipts/{payment}', ReceiptController::class)
    ->middleware('signed')
    ->name('receipts.show');
