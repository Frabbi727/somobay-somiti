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

/*
| Backups (SOMITI_SPEC.md §2): database + files daily, 30 days retained; off-site disk via BACKUP_DISKS.
*/

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->timezone('Asia/Dhaka')
    ->onOneServer();

Schedule::command('backup:run')
    ->dailyAt('03:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:monitor')
    ->dailyAt('09:00')
    ->timezone('Asia/Dhaka')
    ->onOneServer();
