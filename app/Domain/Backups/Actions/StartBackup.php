<?php

declare(strict_types=1);

namespace App\Domain\Backups\Actions;

use App\Domain\Backups\Jobs\RunBackupJob;
use App\Domain\Backups\Services\BackupCatalog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * "Back up now": queues a full backup to every backup disk (super admin only).
 */
final class StartBackup
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageBackups');
        $this->catalog->assertPasswordSet();

        RunBackupJob::dispatch($actor->id);

        $this->causer->withCauser($actor, fn () => activity('backups')->event('backup_requested')->log('backup requested'));
    }
}
