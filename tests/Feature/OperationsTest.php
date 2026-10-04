<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
| SOMITI_SPEC.md §2 / P7.S2: backups are scheduled and retained 30 days; /up reports real health.
*/

function scheduled(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
}

it('schedules the nightly jobs at their Dhaka times', function (string $command, string $cron): void {
    $event = scheduled($command);

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe($cron)
        ->and($event?->timezone)->toBe('Asia/Dhaka');
})->with([
    'dues' => ['somiti:dues:generate', '30 0 1 * *'],
    'late fees' => ['somiti:late-fees:apply', '0 1 * * *'],
    'integrity' => ['somiti:integrity:check', '0 2 * * *'],
    'backup' => ['somiti:backup', '30 23 * * *'],
    'backup clean' => ['backup:clean', '55 23 * * *'],
    'backup monitor' => ['backup:monitor', '0 9 * * *'],
]);

it('keeps daily backups for 30 days', function (): void {
    expect(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))->toBe(30)
        ->and(config('backup.backup.destination.disks'))->not->toBeEmpty();
});

it('reports healthy on /up when the database answers', function (): void {
    $this->get('/up')->assertOk();
});

it('reports unhealthy on /up when the database is down', function (): void {
    DB::shouldReceive('select')->once()->andThrow(new RuntimeException('connection refused'));

    $this->get('/up')->assertServerError();
});
