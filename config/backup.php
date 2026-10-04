<?php

declare(strict_types=1);

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
| Somiti backups (SOMITI_SPEC.md §2): every night at 23:30 Asia/Dhaka (routes/console.php) the
| PostgreSQL database and the uploaded files (payment proofs, member photos) are zipped, encrypted
| with AES-256 (BACKUP_ARCHIVE_PASSWORD) and written to every disk in BACKUP_DISKS — "backups" on
| this server and "offsite" (S3-compatible: Cloudflare R2, Backblaze B2, AWS S3, …).
| The code and .env are not in the backup: keep APP_KEY and BACKUP_ARCHIVE_PASSWORD in a safe place.
*/

$disks = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'backups')))));

return [

    'backup' => [
        'name' => env('BACKUP_NAME', 'somiti'),

        'source' => [
            'files' => [
                'include' => [
                    storage_path('app/private'),
                ],

                'exclude' => [
                    storage_path('app/private/livewire-tmp'),
                    storage_path('app/private/demo'),
                    storage_path('app/backup-temp'),
                ],

                'follow_links' => false,

                'ignore_unreadable_directories' => false,

                'relative_path' => storage_path('app'),
            ],

            'databases' => [
                env('DB_CONNECTION', 'pgsql'),
            ],
        ],

        'database_dump_compressor' => null,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 9,

            'filename_prefix' => 'somiti-',

            'disks' => $disks,

            // Keep writing to the other disks if one (e.g. off-site) is unreachable; the failure is reported.
            'continue_on_failure' => true,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        // AES-256 for every file in the zip.
        'encryption' => 'default',

        'verify_backup' => true,

        'tries' => 3,

        'retry_delay' => 60,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_MAIL_TO') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Somiti Manager'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    'log_channel' => null,

    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', 'somiti'),
            'disks' => $disks,
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 20000,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 30,
            'keep_weekly_backups_for_weeks' => 12,
            'keep_monthly_backups_for_months' => 24,
            'keep_yearly_backups_for_years' => 7,
            'delete_oldest_backups_when_using_more_megabytes_than' => 20000,
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],

];
