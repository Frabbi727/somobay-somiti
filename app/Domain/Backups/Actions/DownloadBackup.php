<?php

declare(strict_types=1);

namespace App\Domain\Backups\Actions;

use App\Domain\Backups\Services\BackupCatalog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\Backup\BackupDestination\Backup;

/**
 * Hands out one listed backup (still encrypted) and writes the download to the audit log.
 */
final class DownloadBackup
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, string $disk, string $path): Backup
    {
        Gate::forUser($actor)->authorize('manageBackups');

        $backup = $this->catalog->find($disk, $path);

        $this->causer->withCauser($actor, fn () => activity('backups')
            ->event('backup_downloaded')
            ->withProperties(['disk' => $disk, 'file' => basename($path)])
            ->log('backup downloaded'));

        return $backup;
    }
}
