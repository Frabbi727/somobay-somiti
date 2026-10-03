<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule (SOMITI_SPEC.md W2, W5) — business times are Asia/Dhaka
|--------------------------------------------------------------------------
*/

Schedule::command('somiti:dues:generate')
    ->monthlyOn(1, '00:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('somiti:late-fees:apply')
    ->dailyAt('01:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('somiti:integrity:check')
    ->dailyAt('02:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();
