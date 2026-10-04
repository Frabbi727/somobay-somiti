<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\Actions\RestoreBackup;
use App\Domain\Backups\Services\BackupCatalog;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Restores a backup file from the server's disk — for archives too large to upload in the browser.
 * The same checks as the panel: a super admin's email, the typed word, a safety backup first.
 */
final class RestoreBackupCommand extends Command
{
    protected $signature = 'somiti:backup:restore {file : Path to the backup .zip} {--by= : Email of the super admin doing the restore}';

    protected $description = 'Replace ALL current data with a backup (a safety backup is taken first)';

    public function handle(RestoreBackup $restore, BackupCatalog $catalog): int
    {
        $file = (string) $this->argument('file');
        $user = User::query()->where('email', (string) $this->option('by'))->first();

        if (! is_file($file) || $user === null) {
            $this->error('Give an existing backup file and --by=<super admin email>.');

            return self::FAILURE;
        }

        $this->warn('This replaces ALL current data with the backup '.basename($file).'. A safety backup is taken first.');

        if ($this->ask('Type RESTORE to continue') !== 'RESTORE') {
            $this->info('Nothing was changed.');

            return self::FAILURE;
        }

        $relative = 'uploads/'.Str::uuid().'.zip';
        $source = fopen($file, 'rb');
        if ($source === false) {
            $this->error('The file cannot be read.');

            return self::FAILURE;
        }
        Storage::disk($catalog->uploadDisk())->writeStream($relative, $source);

        try {
            $result = $restore->fromUpload($user, $relative);
        } catch (DomainRuleViolation $violation) {
            $this->error($violation->getMessage());

            return self::FAILURE;
        }

        $this->info('Restored. Safety backup of the previous data: '.basename($result['safety_backup']));

        return self::SUCCESS;
    }
}
