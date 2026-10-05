<?php

declare(strict_types=1);

namespace App\Domain\Backups\Actions;

use App\Domain\Backups\Jobs\RunRestoreCheckJob;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Queues a restore check now instead of waiting for the 1st of the month (super admin only).
 */
final class StartRestoreCheck
{
    public function __invoke(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageBackups');

        RunRestoreCheckJob::dispatch($actor->id);
    }
}
