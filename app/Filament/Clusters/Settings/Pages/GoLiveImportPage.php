<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\GoLive\Actions\PostOpeningImport;
use App\Domain\GoLive\Actions\UploadOpeningImport;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\GoLive\Services\OpeningTemplate;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Go-live: download the template, upload it until it has no errors, then post it once.
 */
final class GoLiveImportPage extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.clusters.settings.go-live-import';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'go-live-import';

    protected static ?int $navigationSort = 90;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    public static function getNavigationLabel(): string
    {
        return __('golive.title');
    }

    public function getTitle(): string
    {
        return __('golive.title');
    }

    public function getSubheading(): string
    {
        return __('golive.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('create', OpeningImport::class);
    }

    /**
     * The posted import if there is one, else the draft.
     */
    public function current(): ?OpeningImport
    {
        return OpeningImport::query()->where('status', OpeningImportStatus::Posted)->first()
            ?? OpeningImport::query()->where('status', OpeningImportStatus::Draft)->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $import = $this->current();
        $summary = $import->summary ?? [];
        $lines = array_map(fn (mixed $line): array => [
            'code' => is_array($line) ? (string) ($line['code'] ?? '') : '',
            'debit' => Money::ofPoisha(is_array($line) ? (int) ($line['debit_poisha'] ?? 0) : 0),
            'credit' => Money::ofPoisha(is_array($line) ? (int) ($line['credit_poisha'] ?? 0) : 0),
        ], is_array($summary['lines'] ?? null) ? $summary['lines'] : []);
        $voucher = $import?->journal_entry_id === null ? null : JournalEntry::query()->find($import->journal_entry_id);

        return [
            'import' => $import,
            'problems' => array_map(fn (array $error): array => [
                'sheet' => __('golive.sheet.'.($error['sheet'] ?? 'members')),
                'row' => Display::digits((int) ($error['row'] ?? 0)),
                'column' => isset($error['column']) && is_string($error['column']) ? __('golive.columns.'.$error['column']) : '—',
                'message' => __((string) ($error['key'] ?? ''), is_array($error['replace'] ?? null) ? $error['replace'] : []),
            ], $import->errors ?? []),
            'summary' => [
                'members' => Display::digits((int) ($summary['members'] ?? 0)),
                'shares' => Display::digits((int) ($summary['shares'] ?? 0)),
                'savings' => Display::money(Money::ofPoisha((int) ($summary['savings_poisha'] ?? 0))),
                'advance' => Display::money(Money::ofPoisha((int) ($summary['advance_poisha'] ?? 0))),
                'arrears' => Display::money(Money::ofPoisha((int) ($summary['arrears_poisha'] ?? 0))),
                'investments' => Display::digits((int) ($summary['investments'] ?? 0)),
            ],
            'lines' => $lines,
            'totalDebit' => Money::sum(array_map(fn (array $line): Money => $line['debit'], $lines)),
            'totalCredit' => Money::sum(array_map(fn (array $line): Money => $line['credit'], $lines)),
            'voucher' => $voucher,
            'voucherUrl' => $voucher === null ? null : JournalEntryResource::getUrl('view', ['record' => $voucher]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [$this->downloadTemplateAction(), $this->uploadOpeningAction(), $this->postAction()];
    }

    public function downloadTemplateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->label(__('golive.download_template'))
            ->tooltip(__('golive.download_template'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(fn (): StreamedResponse => response()->streamDownload(
                function (): void {
                    echo app(OpeningTemplate::class)->binary();
                },
                'somiti-go-live-template.xlsx',
            ));
    }

    public function uploadOpeningAction(): Action
    {
        return self::tier1(
            Action::make('uploadOpening')
                ->label(__('golive.upload'))
                ->tooltip(__('golive.upload'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('primary')
                ->visible(fn (): bool => $this->current()?->status !== OpeningImportStatus::Posted)
                ->schema([
                    TextInput::make('go_live_month')
                        ->label(__('golive.go_live_month'))
                        ->helperText(__('golive.go_live_month_help'))
                        ->placeholder('2026-07')
                        ->regex('/^\d{4}-\d{2}$/')
                        ->default(fn (): string => (string) YearMonth::current())
                        ->required(),
                    FileUpload::make('file')
                        ->label(__('golive.file'))
                        ->disk('local')
                        ->directory('opening-imports')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(5120)
                        ->storeFileNamesIn('file_name')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $import = DomainActionRunner::run(fn (User $actor): OpeningImport => app(UploadOpeningImport::class)(
                        $actor,
                        (string) $data['file'],
                        (string) ($data['file_name'] ?? 'sheet.xlsx'),
                        YearMonth::parse((string) $data['go_live_month']),
                    ));

                    Notification::make()
                        ->title($import->errors === [] ? __('golive.checked_clean') : __('golive.checked_errors', ['count' => Display::digits(count($import->errors))]))
                        ->status($import->errors === [] ? 'success' : 'warning')
                        ->send();
                }),
            heading: __('golive.upload'),
            description: __('golive.upload_help'),
        );
    }

    public function postAction(): Action
    {
        return self::tier3(
            Action::make('post')
                ->label(__('golive.post'))
                ->tooltip(__('golive.post'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => ($import = $this->current()) !== null && $import->status === OpeningImportStatus::Draft && $import->errors === [])
                ->action(function (): void {
                    $draft = $this->current();

                    if ($draft === null) {
                        return;
                    }

                    DomainActionRunner::run(fn (User $actor): OpeningImport => app(PostOpeningImport::class)($actor, $draft));
                    Notification::make()->title(__('golive.posted'))->success()->send();
                }),
            heading: __('golive.post_heading'),
            expected: 'POST',
            submitLabel: __('golive.post'),
            description: fn (): string => (string) __('golive.post_warning', [
                'members' => Display::digits((int) ($this->current()->summary['members'] ?? 0)),
                'month' => Display::yearMonth($this->current()?->go_live_month),
            ]),
        );
    }
}
