<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\Services\BackupCatalog;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Console\Command;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * The nightly backup (23:30 Asia/Dhaka): refuses to write an unencrypted archive, then runs backup:run.
 */
final class NightlyBackupCommand extends Command
{
    protected $signature = 'somiti:backup';

    protected $description = 'Back up the database and uploaded files, encrypted, to every backup disk';

    public function handle(BackupCatalog $catalog): int
    {
        try {
            $catalog->assertPasswordSet();
        } catch (DomainRuleViolation $violation) {
            $this->error($violation->getMessage());
            event(new BackupHasFailed(new \RuntimeException($violation->getMessage())));

            return self::FAILURE;
        }

        return $this->call('backup:run');
    }
}
