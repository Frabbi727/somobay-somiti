<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Backups\Actions\DownloadBackup;
use App\Domain\Backups\Actions\RestoreBackup;
use App\Domain\Backups\Actions\StartBackup;
use App\Domain\Backups\Services\BackupCatalog;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backups for the super admin: health of each disk, every backup with download and restore,
 * "Back up now", and restoring from an uploaded backup file.
 */
final class BackupsPage extends Page
{
    use ConfirmsWithTier;

    /** The panel accepts uploads up to this size; larger archives use `php artisan somiti:backup:restore`. */
    public const int MAX_UPLOAD_KB = 12 * 1024;

    protected string $view = 'filament.clusters.settings.backups';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'backups';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    public static function getNavigationLabel(): string
    {
        return __('backups.title');
    }

    public function getTitle(): string
    {
        return __('backups.title');
    }

    public function getSubheading(): string
    {
        return __('backups.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('manageBackups');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $catalog = app(BackupCatalog::class);
        $backups = array_map(fn (array $backup): array => [
            ...$backup,
            'when' => Display::dateTime($backup['date']),
            'size_label' => Number::fileSize($backup['size'], precision: 1),
        ], $catalog->all());

        $health = array_map(fn (array $disk): array => [
            ...$disk,
            'newest_label' => $disk['newest'] === null ? __('backups.never') : Display::dateTime($disk['newest']),
            'used_label' => Number::fileSize($disk['used'], precision: 1),
        ], $catalog->health());

        $passwordSet = true;
        try {
            $catalog->assertPasswordSet();
        } catch (\Throwable) {
            $passwordSet = false;
        }

        $now = CarbonImmutable::now(YearMonth::TIMEZONE);
        $tonight = $now->setTime(23, 30);
        $latest = $backups[0] ?? null;

        return [
            'backups' => $backups,
            'health' => $health,
            'passwordSet' => $passwordSet,
            'allHealthy' => $health !== [] && collect($health)->every(fn (array $disk): bool => $disk['healthy']),
            'latest' => $latest,
            'next' => Display::dateTime($now->lessThan($tonight) ? $tonight : $tonight->addDay()),
            'totalSize' => Number::fileSize(array_sum(array_column($backups, 'size')), precision: 1),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [$this->backupNowAction(), $this->uploadRestoreAction()];
    }

    public function backupNowAction(): Action
    {
        return self::tier3(
            Action::make('backupNow')
                ->label(__('backups.backup_now'))
                ->tooltip(__('backups.backup_now_help'))
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->color('primary')
                ->action(function (): void {
                    DomainActionRunner::run(fn (User $actor) => app(StartBackup::class)($actor));
                    Notification::make()->title(__('backups.notify.started'))->success()->send();
                }),
            heading: __('backups.backup_now'),
            expected: 'BACKUP',
            submitLabel: __('backups.backup_now'),
            description: __('backups.backup_now_help'),
        );
    }

    public function uploadRestoreAction(): Action
    {
        return self::tier3(
            Action::make('uploadRestore')
                ->label(__('backups.upload_restore'))
                ->tooltip(__('backups.upload_restore'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('danger')
                ->action(function (array $data): void {
                    $result = DomainActionRunner::run(fn (User $actor): array => app(RestoreBackup::class)->fromUpload($actor, (string) $data['archive']));
                    $this->restored($result);
                }),
            heading: __('backups.restore_heading'),
            expected: 'RESTORE',
            submitLabel: __('backups.restore'),
            description: __('backups.restore_warning'),
            fields: [
                FileUpload::make('archive')
                    ->label(__('backups.file'))
                    ->helperText(__('backups.file_help', ['size' => Number::fileSize(self::MAX_UPLOAD_KB * 1024)]))
                    ->disk('backups')
                    ->directory('uploads')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                    ->maxSize(self::MAX_UPLOAD_KB)
                    ->required(),
            ],
        );
    }

    public function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('backups.download'))
            ->tooltip(__('backups.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->iconButton()
            ->action(function (array $arguments): StreamedResponse {
                $backup = DomainActionRunner::run(fn (User $actor) => app(DownloadBackup::class)($actor, (string) $arguments['disk'], (string) $arguments['path']));

                return response()->streamDownload(function () use ($backup): void {
                    $stream = $backup->stream();
                    fpassthru($stream);
                    fclose($stream);
                }, basename($backup->path()), ['Content-Type' => 'application/zip']);
            });
    }

    public function restoreAction(): Action
    {
        return self::tier3(
            Action::make('restore')
                ->label(__('backups.restore'))
                ->tooltip(__('backups.restore'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->iconButton()
                ->action(function (array $arguments): void {
                    $result = DomainActionRunner::run(fn (User $actor): array => app(RestoreBackup::class)->fromList($actor, (string) $arguments['disk'], (string) $arguments['path']));
                    $this->restored($result);
                }),
            heading: __('backups.restore_heading'),
            expected: 'RESTORE',
            submitLabel: __('backups.restore'),
            description: __('backups.restore_warning'),
        );
    }

    /**
     * @param  array{safety_backup: string, files: int}  $result
     */
    private function restored(array $result): void
    {
        Notification::make()
            ->title(__('backups.notify.restored'))
            ->body(__('backups.notify.restored_body', ['safety' => basename($result['safety_backup']), 'files' => Display::digits($result['files'])]))
            ->success()
            ->persistent()
            ->send();
    }
}
