<?php

declare(strict_types=1);

namespace App\Domain\Backups\Jobs;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs a backup in the background ("Back up now") and tells the person who asked how it went.
 */
final class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $requestedBy) {}

    public function handle(): void
    {
        $exit = Artisan::call('backup:run');
        $user = User::query()->find($this->requestedBy);

        if ($user === null) {
            return;
        }

        $notification = Notification::make()->title(__($exit === 0 ? 'backups.notify.done' : 'backups.notify.failed', [], $user->locale));

        ($exit === 0 ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }
}
