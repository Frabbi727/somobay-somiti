<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Backups\Actions\DownloadBackup;
use App\Domain\Backups\Actions\RestoreBackup;
use App\Domain\Backups\Actions\StartBackup;
use App\Domain\Backups\Jobs\RunBackupJob;
use App\Domain\Backups\Services\BackupCatalog;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\BackupsPage;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Nightly encrypted backups to this server and off-site, and the super admin's Backups page:
| back up now, download, restore (from the list or an uploaded file).
*/

const TEST_BACKUP_PASSWORD = 'test-backup-password-1234';

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Storage::fake('backups');
    config(['backup.backup.password' => TEST_BACKUP_PASSWORD, 'backup.backup.destination.disks' => ['backups'], 'backup.monitor_backups.0.disks' => ['backups']]);
    $this->admin = userWithRole(Role::SuperAdmin);
});

/**
 * Runs a real backup and returns its path on the "backups" disk.
 */
function takeBackup(): string
{
    expect(Artisan::call('somiti:backup'))->toBe(0);
    // Archive names carry the second they were taken; later backups in a test must not share it.
    test()->travel(1)->minutes();

    return collect(app(BackupCatalog::class)->all())->first()['path'];
}

it('backs up automatically every night at 23:30 Bangladesh time, then thins out old backups', function (): void {
    $events = collect(app(Schedule::class)->events());
    $backup = $events->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'somiti:backup') && ! str_contains((string) $event->command, 'restore'));
    $clean = $events->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'backup:clean'));

    expect($backup?->expression)->toBe('30 23 * * *')
        ->and($backup?->timezone)->toBe('Asia/Dhaka')
        ->and($clean?->expression)->toBe('55 23 * * *');
});

it('writes an AES-256 encrypted archive with the database and the uploaded files, but not the code or .env', function (): void {
    Storage::disk('local')->put('member-photos/test-photo.png', 'photo');
    $path = takeBackup();
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path($path));

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = (array) $zip->statIndex($i);
        $names[] = $stat['name'];
        if (! str_ends_with((string) $stat['name'], '/')) {
            expect($stat['encryption_method'])->toBe(ZipArchive::EM_AES_256);
        }
    }

    expect($names)->toContain('db-dumps/postgresql-somiti_test.sql')
        ->toContain('private/member-photos/test-photo.png')
        ->not->toContain('.env');

    $zip->setPassword('wrong-password');
    expect(@$zip->getFromName('db-dumps/postgresql-somiti_test.sql'))->toBeFalse();

    $zip->setPassword(TEST_BACKUP_PASSWORD);
    expect((string) $zip->getFromName('db-dumps/postgresql-somiti_test.sql'))->toContain('PostgreSQL database dump');

    Storage::disk('local')->delete('member-photos/test-photo.png');
});

it('refuses to back up without a strong password and alerts the super admins', function (): void {
    config(['backup.backup.password' => 'short']);

    expect(Artisan::call('somiti:backup'))->toBe(1)
        ->and(app(BackupCatalog::class)->all())->toBe([])
        ->and($this->admin->notifications()->count())->toBe(1);
});

it('shows the Backups page to the super admin only', function (Role $role, int $status): void {
    $this->actingAs($role === Role::SuperAdmin ? $this->admin : userWithRole($role))
        ->get(BackupsPage::getUrl())
        ->assertStatus($status);
})->with([[Role::SuperAdmin, 200], [Role::President, 403], [Role::Accountant, 403], [Role::Auditor, 403]]);

it('lists backups with their health and queues "back up now" with a typed confirmation', function (): void {
    takeBackup();
    Queue::fake();
    $this->actingAs($this->admin);

    Livewire::test(BackupsPage::class)
        ->assertSee(__('backups.ok', [], 'bn'))
        ->assertSee('somiti-')
        ->callAction('backupNow', data: ['confirm_text' => 'BACKUP'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('backups.notify.started', [], 'bn'));

    Queue::assertPushed(RunBackupJob::class, fn (RunBackupJob $job): bool => $job->requestedBy === $this->admin->id);
    expect(AuditEntry::query()->where('event', 'backup_requested')->sole()->causer_id)->toBe($this->admin->id);
});

it('lets only the super admin back up, download or restore', function (): void {
    $president = userWithRole(Role::President);

    expect(fn () => app(StartBackup::class)($president))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DownloadBackup::class)($president, 'backups', 'x.zip'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreBackup::class)->fromUpload($president, 'uploads/x.zip'))->toThrow(AuthorizationException::class);
});

it('downloads only a listed backup and logs the download', function (): void {
    $path = takeBackup();

    expect(app(DownloadBackup::class)($this->admin, 'backups', $path)->path())->toBe($path)
        ->and(AuditEntry::query()->where('event', 'backup_downloaded')->sole()->properties?->get('file'))->toBe(basename($path))
        ->and(fn () => app(DownloadBackup::class)($this->admin, 'backups', '../../.env'))->toThrow(DomainRuleViolation::class)
        ->and(fn () => app(DownloadBackup::class)($this->admin, 'local', $path))->toThrow(DomainRuleViolation::class);

    $this->actingAs($this->admin);
    Livewire::test(BackupsPage::class)
        ->callAction('download', arguments: ['disk' => 'backups', 'path' => $path])
        ->assertFileDownloaded(basename($path));
});

it('refuses to restore a file that is not one of our backups, changing nothing', function (): void {
    Process::fake();
    Storage::disk('backups')->makeDirectory('uploads');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backups')->path('uploads/other.zip'), ZipArchive::CREATE);
    $zip->addFromString('db-dumps/postgresql-somiti.sql', '-- PostgreSQL database dump');
    $zip->setEncryptionName('db-dumps/postgresql-somiti.sql', ZipArchive::EM_AES_256, 'someone-elses-password');
    $zip->close();

    Storage::disk('backups')->put('uploads/notes.zip', 'not a zip');

    expect(fn () => app(RestoreBackup::class)->fromUpload($this->admin, 'uploads/other.zip'))->toThrow(DomainRuleViolation::class)
        ->and(fn () => app(RestoreBackup::class)->fromUpload($this->admin, 'uploads/notes.zip'))->toThrow(DomainRuleViolation::class)
        ->and(fn () => app(RestoreBackup::class)->fromUpload($this->admin, 'uploads/../../.env'))->toThrow(DomainRuleViolation::class);

    Process::assertNothingRan();
    expect(app(BackupCatalog::class)->all())->toBe([])
        ->and(Storage::disk('backups')->exists('uploads/other.zip'))->toBeFalse();
});

it('restores in one transaction after a safety backup, then records it in the audit log', function (): void {
    $path = takeBackup();
    Process::fake(['*psql*' => Process::result()]);
    $this->actingAs($this->admin);

    Livewire::test(BackupsPage::class)
        ->callAction('restore', data: ['confirm_text' => 'RESTORE'], arguments: ['disk' => 'backups', 'path' => $path])
        ->assertHasNoActionErrors()
        ->assertNotified(__('backups.notify.restored', [], 'bn'));

    Process::assertRan(fn (PendingProcess $process): bool => in_array('--single-transaction', (array) $process->command, true)
        && in_array('ON_ERROR_STOP=1', (array) $process->command, true));

    expect(app(BackupCatalog::class)->all())->toHaveCount(2) // the original + the safety backup
        ->and(AuditEntry::query()->where('event', 'backup_restored')->sole()->causer_id)->toBe($this->admin->id);
});

it('leaves the data alone when the database restore fails', function (): void {
    $path = takeBackup();
    Process::fake(['*psql*' => Process::result(exitCode: 3, errorOutput: 'ERROR: boom')]);

    expect(fn () => app(RestoreBackup::class)->fromList($this->admin, 'backups', $path))
        ->toThrow(DomainRuleViolation::class, __('backups.errors.restore_failed'));

    expect(AuditEntry::query()->where('event', 'backup_restored')->exists())->toBeFalse();
});
