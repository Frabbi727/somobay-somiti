<?php

declare(strict_types=1);

namespace App\Domain\Backups\Services;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\BackupsPage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The monthly restore drill (SOMITI_SPEC.md §1.7): restores the newest backup — off-site when there
 * is one — into a throwaway database, checks the books balance and hold data, then drops it. Proves
 * the backups can really be opened and restored, not just that the files exist. The live database is
 * never touched.
 */
final class RestoreCheck
{
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly BackupRestorer $restorer,
    ) {}

    /**
     * @return array{passed: bool, backup: string|null, disk: string|null, taken: string|null, members: int, vouchers: int, difference: int, error: string|null}
     */
    public function run(): array
    {
        $result = ['passed' => false, 'backup' => null, 'disk' => null, 'taken' => null, 'members' => 0, 'vouchers' => 0, 'difference' => 0, 'error' => null];
        $work = null;
        $database = $this->scratchDatabase();

        try {
            $this->catalog->assertPasswordSet();
            $backups = collect($this->catalog->all());
            $backup = $backups->firstWhere('disk', 'offsite') ?? $backups->first();

            if ($backup === null) {
                throw DomainRuleViolation::because('backups.check.no_backup');
            }

            $result = [...$result, 'backup' => $backup['name'], 'disk' => $backup['disk'], 'taken' => $backup['date']->toIso8601String()];

            $work = $this->restorer->workDirectory();
            $zip = $work.'/backup.zip';
            $stream = Storage::disk($backup['disk'])->readStream($backup['path']);
            if ($stream === null) {
                throw DomainRuleViolation::because('backups.errors.not_found');
            }
            $target = fopen($zip, 'wb');
            if ($target === false) {
                throw DomainRuleViolation::because('backups.errors.cannot_open');
            }
            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);

            $dump = $this->restorer->extract($zip, $work);

            $this->admin('DROP DATABASE IF EXISTS "'.$database.'"');
            $this->admin('CREATE DATABASE "'.$database.'"');
            $this->restorer->restoreDatabase($dump, $work.'/restore.sql', $database);

            $counts = $this->restorer->psql(['--tuples-only', '--no-align', '--command',
                'SELECT (SELECT COALESCE(SUM(debit_poisha) - SUM(credit_poisha), 0) FROM journal_lines), (SELECT COUNT(*) FROM journal_entries), (SELECT COUNT(*) FROM members)',
            ], $database);

            if ($counts->failed()) {
                throw DomainRuleViolation::because('backups.check.query_failed');
            }

            [$difference, $vouchers, $members] = array_map('intval', explode('|', trim($counts->output())) + [0, 0, 0]);
            $result = [...$result, 'difference' => $difference, 'vouchers' => $vouchers, 'members' => $members];
            $result['passed'] = $difference === 0;

            if (! $result['passed']) {
                $result['error'] = __('backups.check.unbalanced', ['difference' => $difference]);
            }
        } catch (Throwable $exception) {
            $result['error'] = $exception instanceof DomainRuleViolation ? $exception->getMessage() : class_basename($exception).': '.$exception->getMessage();
            report($exception);
        } finally {
            $this->admin('DROP DATABASE IF EXISTS "'.$database.'"', quiet: true);
            if ($work !== null) {
                File::deleteDirectory($work);
            }
        }

        activity('backups')
            ->event($result['passed'] ? 'restore_check_passed' : 'restore_check_failed')
            ->withProperties($result)
            ->log($result['passed'] ? 'restore check passed' : 'restore check failed');

        if (! $result['passed']) {
            $this->alert((string) $result['error']);
        }

        return $result;
    }

    /**
     * The throwaway database: the live name plus a suffix, only letters, digits and underscores.
     */
    public function scratchDatabase(): string
    {
        $live = (string) config('database.connections.'.config('database.default').'.database');

        return preg_replace('/[^A-Za-z0-9_]/', '_', $live).'_restore_check';
    }

    private function admin(string $sql, bool $quiet = false): void
    {
        $result = $this->restorer->psql(['--command', $sql], 'postgres');

        if ($result->failed() && ! $quiet) {
            throw DomainRuleViolation::because('backups.check.cannot_create_database');
        }
    }

    private function alert(string $error): void
    {
        foreach (User::query()->role(Role::SuperAdmin->value)->whereNull('deactivated_at')->get() as $user) {
            Notification::make()
                ->danger()
                ->title(__('backups.check.failed_alert', [], $user->locale))
                ->body($error)
                ->actions([Action::make('open')->label(__('backups.title', [], $user->locale))->url(BackupsPage::getUrl(panel: 'admin'))])
                ->sendToDatabase($user);
        }
    }

    /**
     * The latest drill result, for the Backups page.
     *
     * @return array{passed: bool, at: CarbonImmutable, backup: string|null}|null
     */
    public static function latest(): ?array
    {
        $entry = AuditEntry::query()
            ->where('log_name', 'backups')
            ->whereIn('event', ['restore_check_passed', 'restore_check_failed'])
            ->latest('id')
            ->first();

        return $entry?->created_at === null ? null : [
            'passed' => $entry->event === 'restore_check_passed',
            'at' => CarbonImmutable::instance($entry->created_at),
            'backup' => $entry->properties?->get('backup'),
        ];
    }
}
