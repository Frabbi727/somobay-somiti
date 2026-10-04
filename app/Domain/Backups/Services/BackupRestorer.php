<?php

declare(strict_types=1);

namespace App\Domain\Backups\Services;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use ZipArchive;

/**
 * Puts a backup archive back: the whole database in one transaction (all or nothing) and the uploaded
 * files (proofs, photos) copied over the current ones. Before touching anything it checks that the
 * archive opens with this installation's password and holds a PostgreSQL dump, and takes a safety
 * backup of the current data on this server.
 */
final class BackupRestorer
{
    public function __construct(private readonly BackupCatalog $catalog) {}

    /**
     * Opens the archive and finds its database dump, without changing anything.
     *
     * @return string the dump's name inside the archive
     *
     * @throws DomainRuleViolation
     */
    public function verify(string $zipPath): string
    {
        $this->catalog->assertPasswordSet();

        $zip = $this->open($zipPath);

        try {
            $dump = $this->dumpName($zip);
            $head = $this->readStart($zip, $dump);

            if (! str_contains($head, 'PostgreSQL database dump')) {
                throw DomainRuleViolation::because('backups.errors.not_a_backup');
            }

            return $dump;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{safety_backup: string, files: int}
     *
     * @throws DomainRuleViolation
     */
    public function restore(string $zipPath): array
    {
        $dumpName = $this->verify($zipPath);
        $safety = $this->safetyBackup();

        $work = storage_path('app/backup-temp/restore-'.Str::uuid());
        File::ensureDirectoryExists($work, 0700);

        try {
            $zip = $this->open($zipPath);
            if (! $zip->extractTo($work)) {
                $zip->close();
                throw DomainRuleViolation::because('backups.errors.cannot_open');
            }
            $zip->close();

            $this->restoreDatabase($work.'/'.$dumpName, $work.'/restore.sql');
            $files = $this->restoreFiles($work.'/private');
        } finally {
            File::deleteDirectory($work);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['safety_backup' => $safety, 'files' => $files];
    }

    private function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw DomainRuleViolation::because('backups.errors.cannot_open');
        }

        $zip->setPassword((string) config('backup.backup.password'));

        return $zip;
    }

    private function dumpName(ZipArchive $zip): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^db-dumps/postgresql-[^/]+\.sql$#', $name) === 1) {
                return $name;
            }
        }

        throw DomainRuleViolation::because('backups.errors.not_a_backup');
    }

    private function readStart(ZipArchive $zip, string $name): string
    {
        $stream = @$zip->getStream($name);
        $head = $stream === false ? false : @fread($stream, 2048);

        if (! is_string($head) || $head === '') {
            // A wrong password shows up here: the encrypted entry cannot be read.
            throw DomainRuleViolation::because('backups.errors.cannot_open');
        }

        return $head;
    }

    /**
     * A backup of what is here now, on this server only, so a restore can itself be undone.
     */
    private function safetyBackup(): string
    {
        $before = collect($this->catalog->all())->where('disk', 'backups')->pluck('path')->all();

        $exit = Artisan::call('backup:run', ['--only-to-disk' => 'backups', '--disable-notifications' => true]);

        $new = collect($this->catalog->all())->where('disk', 'backups')->pluck('path')->diff($before)->first();

        if ($exit !== 0 || $new === null) {
            throw DomainRuleViolation::because('backups.errors.safety_backup_failed');
        }

        return (string) $new;
    }

    /**
     * Replaces the whole public schema with the dump inside one transaction: if any statement fails,
     * PostgreSQL rolls everything back and the current data stays as it was.
     */
    private function restoreDatabase(string $dumpPath, string $scriptPath): void
    {
        $in = fopen($dumpPath, 'rb');
        $out = fopen($scriptPath, 'wb');

        if ($in === false || $out === false) {
            throw DomainRuleViolation::because('backups.errors.cannot_open');
        }

        fwrite($out, "DROP SCHEMA public CASCADE;\nCREATE SCHEMA public;\n");
        while (($line = fgets($in)) !== false) {
            // Ownership follows whoever runs the restore on this server.
            if (preg_match('/^ALTER .+ OWNER TO .+;\s*$/', $line) === 1) {
                continue;
            }
            fwrite($out, $line);
        }
        fclose($in);
        fclose($out);
        chmod($scriptPath, 0600);

        $connection = (array) config('database.connections.'.config('database.default'));
        $binaries = (string) data_get($connection, 'dump.dump_binary_path', '');
        $psql = $binaries === '' ? 'psql' : rtrim($binaries, '/').'/psql';

        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->run([
                $psql,
                '--no-psqlrc', '--quiet', '--single-transaction', '--set', 'ON_ERROR_STOP=1',
                '--host', (string) ($connection['host'] ?? '127.0.0.1'),
                '--port', (string) ($connection['port'] ?? '5432'),
                '--username', (string) ($connection['username'] ?? ''),
                '--dbname', (string) ($connection['database'] ?? ''),
                '--file', $scriptPath,
            ]);

        if ($result->failed()) {
            report(new \RuntimeException('Backup restore failed: '.Str::limit($result->errorOutput(), 2000)));

            throw DomainRuleViolation::because('backups.errors.restore_failed');
        }
    }

    /**
     * Copies the backed-up files over the current ones; files added since the backup are kept.
     */
    private function restoreFiles(string $from): int
    {
        if (! is_dir($from)) {
            return 0;
        }

        $count = 0;
        $target = storage_path('app/private');

        foreach (File::allFiles($from, hidden: true) as $file) {
            $destination = $target.'/'.$file->getRelativePathname();
            File::ensureDirectoryExists(dirname($destination));
            File::copy($file->getPathname(), $destination);
            $count++;
        }

        return $count;
    }
}
