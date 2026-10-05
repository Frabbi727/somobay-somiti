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
| Backups (SOMITI_SPEC.md §2): every night at 23:30 the database and uploaded files, AES-256 encrypted,
| to every disk in BACKUP_DISKS (this server + off-site). Old ones are thinned out afterwards
| (config/backup.php cleanup); the health check in the morning emails and alerts super admins.
*/

Schedule::command('somiti:backup')
    ->dailyAt('23:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:clean')
    ->dailyAt('23:55')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();

// Monthly restore drill: the newest backup is restored into a throwaway database and checked.
Schedule::command('somiti:backup:check-restore')
    ->monthlyOn(1, '04:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:monitor')
    ->dailyAt('09:00')
    ->timezone('Asia/Dhaka')
    ->onOneServer();
