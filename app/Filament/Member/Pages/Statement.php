<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Member\Concerns\ScopedToMember;
use App\Reports\Definitions\MemberStatementReport;
use App\Reports\ReportExporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The member's own statement for a date range, the same report the staff print, with a PDF download.
 *
 * @property-read Schema $form
 */
final class Statement extends Page
{
    use ScopedToMember;

    protected string $view = 'filament.member.statement';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    /** @var array<string, mixed> */
    public array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.statement');
    }

    public function getTitle(): string
    {
        return __('portal.nav.statement');
    }

    public function mount(): void
    {
        $defaults = app(MemberStatementReport::class)->defaults();
        $this->form->fill(['from' => $defaults['from'] ?? null, 'until' => $defaults['until'] ?? null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(2)->components([
            DatePicker::make('from')->label(__('reports.ledger.from'))->native(false)->live(),
            DatePicker::make('until')->label(__('reports.ledger.until'))->native(false)->live(),
        ]);
    }

    /**
     * The filters with the member fixed to the signed-in one, whatever the browser sends.
     *
     * @return array<string, mixed>
     */
    private function reportFilters(): array
    {
        return ['from' => $this->filters['from'] ?? null, 'until' => $this->filters['until'] ?? null, 'member' => self::member()->id];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['data' => app(MemberStatementReport::class)->data($this->reportFilters())];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label(__('reports.download_pdf'))
                ->tooltip(__('reports.download_pdf'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->action(fn (): ?StreamedResponse => $this->download()),
        ];
    }

    private function download(): ?StreamedResponse
    {
        $report = app(MemberStatementReport::class);
        $filters = $this->reportFilters();
        $content = app(ReportExporter::class)->pdf($report, $filters);

        if ($content === null) {
            return null;
        }

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $report->filename($filters).'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
