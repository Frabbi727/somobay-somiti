<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Navigation\NavGroup;
use App\Reports\Contracts\Report;
use App\Reports\ReportExporter;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * A register page: the report's filters, its table, and PDF/Excel downloads of the same data.
 *
 * @property-read Schema $form
 */
abstract class ReportPage extends Page
{
    protected string $view = 'filament.pages.reports.generic';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Reports;

    /** @var array<string, mixed> */
    public array $filters = [];

    /**
     * @return class-string<Report>
     */
    abstract protected static function reportClass(): string;

    public static function report(): Report
    {
        return app(static::reportClass());
    }

    public static function getNavigationLabel(): string
    {
        return static::report()->title();
    }

    public function getTitle(): string
    {
        return static::report()->title();
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewReports');
    }

    public function mount(): void
    {
        $this->form->fill(static::report()->defaults());
    }

    public function form(Schema $schema): Schema
    {
        $filters = static::report()->filters();

        return $schema->statePath('filters')->columns(min(4, max(1, count($filters))))->components($filters);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = static::report();

        return ['data' => $report->data($this->filters), 'partial' => $report->view()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label(__('reports.download_pdf'))
                ->tooltip(__('reports.download_pdf'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->disabled(fn (): bool => static::report()->data($this->filters) === null)
                ->action(fn (): ?StreamedResponse => $this->download('pdf')),
            Action::make('excel')
                ->label(__('reports.download_excel'))
                ->tooltip(__('reports.download_excel'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->disabled(fn (): bool => static::report()->data($this->filters) === null)
                ->action(fn (): ?StreamedResponse => $this->download('xlsx')),
        ];
    }

    private function download(string $extension): ?StreamedResponse
    {
        $report = static::report();
        $exporter = app(ReportExporter::class);
        $content = $extension === 'pdf' ? $exporter->pdf($report, $this->filters) : $exporter->excel($report, $this->filters);

        if ($content === null) {
            return null;
        }

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $report->filename($this->filters).'.'.$extension, [
            'Content-Type' => $extension === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
