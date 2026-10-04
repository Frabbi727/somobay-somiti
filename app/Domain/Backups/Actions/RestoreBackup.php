<?php

declare(strict_types=1);

namespace App\Domain\Backups\Actions;

use App\Domain\Backups\Services\BackupCatalog;
use App\Domain\Backups\Services\BackupRestorer;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Replaces all current data with a backup — either one from the list or a file the super admin
 * uploaded. Super admin only; a safety backup of the current data is taken first, and the database
 * part is all-or-nothing. The restore is written to the (restored) audit log afterwards.
 */
final class RestoreBackup
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupRestorer $restorer,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @return array{safety_backup: string, files: int}
     */
    public function fromList(User $actor, string $disk, string $path): array
    {
        Gate::forUser($actor)->authorize('manageBackups');

        $this->catalog->find($disk, $path);

        if ($disk === $this->catalog->uploadDisk()) {
            return $this->restore($actor, Storage::disk($disk)->path($path), basename($path), $disk);
        }

        $copy = $this->copyToLocal($disk, $path);

        try {
            return $this->restore($actor, $this->catalog->absoluteUploadPath($copy), basename($path), $disk);
        } finally {
            Storage::disk($this->catalog->uploadDisk())->delete($copy);
        }
    }

    /**
     * @param  string  $uploadedPath  path on the "backups" disk where the upload was stored
     * @return array{safety_backup: string, files: int}
     */
    public function fromUpload(User $actor, string $uploadedPath): array
    {
        Gate::forUser($actor)->authorize('manageBackups');

        if (! str_starts_with($uploadedPath, 'uploads/') || str_contains($uploadedPath, '..') || ! Storage::disk($this->catalog->uploadDisk())->exists($uploadedPath)) {
            throw DomainRuleViolation::because('backups.errors.not_found');
        }

        try {
            return $this->restore($actor, $this->catalog->absoluteUploadPath($uploadedPath), basename($uploadedPath), 'upload');
        } finally {
            Storage::disk($this->catalog->uploadDisk())->delete($uploadedPath);
        }
    }

    /**
     * @return array{safety_backup: string, files: int}
     */
    private function restore(User $actor, string $zipPath, string $name, string $source): array
    {
        $result = $this->restorer->restore($zipPath);

        // The audit log was replaced with the backup's; record the restore in it now.
        $this->causer->withCauser($actor, fn () => activity('backups')
            ->event('backup_restored')
            ->withProperties(['file' => $name, 'source' => $source, 'safety_backup' => basename($result['safety_backup']), 'files' => $result['files'], 'by' => $actor->email])
            ->log('backup restored'));

        return $result;
    }

    /**
     * Brings an off-site backup to this server for restoring; returns its path on the "backups" disk.
     */
    private function copyToLocal(string $disk, string $path): string
    {
        $relative = 'uploads/'.basename($path);
        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === null) {
            throw DomainRuleViolation::because('backups.errors.not_found');
        }

        Storage::disk($this->catalog->uploadDisk())->writeStream($relative, $stream);

        return $relative;
    }
}
