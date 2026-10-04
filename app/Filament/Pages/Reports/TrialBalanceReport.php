<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Enums\Area;
use App\Filament\Navigation\NavGroup;
use App\Models\User;
use App\Reports\TrialBalanceDocument;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * @property-read Schema $form
 */
final class TrialBalanceReport extends Page
{
    protected string $view = 'filament.pages.reports.trial-balance';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Reports;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'reports/trial-balance';

    /** @var array<string, mixed> */
    public array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('reports.trial_balance.title');
    }

    public function getTitle(): string
    {
        return __('reports.trial_balance.title');
    }

    public static function canAccess(): bool
    {
        return Area::FinancialReports->allows(auth()->user() instanceof User ? auth()->user() : null);
    }

    public function mount(): void
    {
        $this->form->fill(['as_of' => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                DatePicker::make('as_of')
                    ->label(__('reports.trial_balance.as_of'))
                    ->native(false)
                    ->required()
                    ->live(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(TrialBalanceDocument::class)->data($this->asOf());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label(__('reports.download_pdf'))
                ->tooltip(__('reports.download_pdf'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->action(fn (): StreamedResponse => $this->download('pdf')),
            Action::make('excel')
                ->label(__('reports.download_excel'))
                ->tooltip(__('reports.download_excel'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->download('xlsx')),
        ];
    }

    private function download(string $extension): StreamedResponse
    {
        $document = app(TrialBalanceDocument::class);
        $asOf = $this->asOf();
        $content = $extension === 'pdf' ? $document->pdf($asOf) : $document->excel($asOf);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $document->filename($asOf, $extension), [
            'Content-Type' => $extension === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function asOf(): CarbonImmutable
    {
        $date = $this->filters['as_of'] ?? null;

        return is_string($date) && $date !== ''
            ? CarbonImmutable::parse($date, YearMonth::TIMEZONE)
            : CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay();
    }
}
