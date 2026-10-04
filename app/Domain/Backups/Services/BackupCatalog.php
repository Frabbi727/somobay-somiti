<?php

declare(strict_types=1);

namespace App\Domain\Backups\Services;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\MonitoredBackupsConfig;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;

/**
 * The backups on every configured disk (config backup.backup.destination.disks) and their health.
 */
final class BackupCatalog
{
    /** Archives must be at least this long a password (AES-256 is only as strong as its password). */
    public const int MIN_PASSWORD_LENGTH = 16;

    public static function backupName(): string
    {
        return (string) config('backup.backup.name');
    }

    /**
     * @return list<string>
     */
    public function disks(): array
    {
        return array_values((array) config('backup.backup.destination.disks'));
    }

    /**
     * Refuses to back up (or restore) without a strong archive password.
     *
     * @throws DomainRuleViolation
     */
    public function assertPasswordSet(): void
    {
        if (mb_strlen((string) config('backup.backup.password')) < self::MIN_PASSWORD_LENGTH) {
            throw DomainRuleViolation::because('backups.errors.no_password', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
    }

    /**
     * Every backup, newest first.
     *
     * @return list<array{disk: string, path: string, name: string, date: CarbonImmutable, size: int}>
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->disks() as $disk) {
            $destination = BackupDestination::create($disk, self::backupName());

            if (! $destination->isReachable()) {
                continue;
            }

            foreach ($destination->backups() as $backup) {
                /** @var Backup $backup */
                $rows[] = [
                    'disk' => $disk,
                    'path' => $backup->path(),
                    'name' => basename($backup->path()),
                    'date' => CarbonImmutable::instance($backup->date()),
                    'size' => (int) $backup->sizeInBytes(),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $rows;
    }

    /**
     * A backup that really is in the list — never an arbitrary path typed into a request.
     *
     * @throws DomainRuleViolation
     */
    public function find(string $disk, string $path): Backup
    {
        if (! in_array($disk, $this->disks(), true)) {
            throw DomainRuleViolation::because('backups.errors.not_found');
        }

        foreach (BackupDestination::create($disk, self::backupName())->backups() as $backup) {
            /** @var Backup $backup */
            if ($backup->path() === $path) {
                return $backup;
            }
        }

        throw DomainRuleViolation::because('backups.errors.not_found');
    }

    /**
     * Health of each disk: reachable, how many backups, the newest, storage used and any problem.
     *
     * @return list<array{disk: string, reachable: bool, healthy: bool, count: int, newest: CarbonImmutable|null, used: int, problems: list<string>}>
     */
    public function health(): array
    {
        $statuses = BackupDestinationStatusFactory::createForMonitorConfig(
            MonitoredBackupsConfig::fromArray((array) config('backup.monitor_backups')),
        );

        return array_values($statuses->map(function (BackupDestinationStatus $status): array {
            $destination = $status->backupDestination();
            $reachable = $destination->isReachable();
            $newest = $reachable ? $destination->newestBackup() : null;

            return [
                'disk' => $destination->diskName(),
                'reachable' => $reachable,
                'healthy' => $status->isHealthy(),
                'count' => $reachable ? $destination->backups()->count() : 0,
                'newest' => $newest === null ? null : CarbonImmutable::instance($newest->date()),
                'used' => $reachable ? (int) $destination->usedStorage() : 0,
                'problems' => array_values($status->failureMessages()->map(fn (array $failure): string => (string) $failure['message'])->all()),
            ];
        })->all());
    }

    /**
     * Where an uploaded backup waits until it is restored.
     */
    public function uploadDisk(): string
    {
        return 'backups';
    }

    public function absoluteUploadPath(string $relative): string
    {
        return Storage::disk($this->uploadDisk())->path($relative);
    }
}
