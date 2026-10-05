<?php

declare(strict_types=1);

namespace App\Domain\Backups\Jobs;

use App\Domain\Backups\Services\RestoreCheck;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Test restore now": runs the restore check in the background and tells whoever asked.
 */
final class RunRestoreCheckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly int $requestedBy) {}

    public function handle(RestoreCheck $check): void
    {
        $result = $check->run();
        $user = User::query()->find($this->requestedBy);

        if ($user === null || ! $result['passed']) {
            return; // failures already alert every super admin
        }

        Notification::make()
            ->success()
            ->title(__('backups.check.passed_notify', ['members' => $result['members'], 'vouchers' => $result['vouchers']], $user->locale))
            ->sendToDatabase($user);
    }
}
