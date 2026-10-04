<?php

declare(strict_types=1);

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ReceiptController;
use BezhanSalleh\LanguageSwitch\Http\Middleware\SwitchLanguageLocale;
use Illuminate\Support\Facades\Route;

// Home: choose the member portal (Filament panel "member", /portal) or the staff panel (/admin).
Route::view('/', 'home')->middleware(SwitchLanguageLocale::class)->name('home');

Route::post('/locale/{locale}', LocaleController::class)->name('locale');

Route::get('/receipts/{payment}', ReceiptController::class)
    ->middleware('signed')
    ->name('receipts.show');
