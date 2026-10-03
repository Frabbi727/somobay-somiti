<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Reporting\MemberReports;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Money\Money;
use App\Support\Spreadsheet\Workbook;
use App\Support\Time\YearMonth;

final class CollectionSummaryReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly MemberReports $members) {}

    public function title(): string
    {
        return __('reports.collection.title');
    }

    public function filters(): array
    {
        return [$this->monthField('from', __('reports.ledger.from')), $this->monthField('until', __('reports.ledger.until'))];
    }

    public function defaults(): array
    {
        return ['from' => substr($this->fiscalYearStart(), 0, 7), 'until' => (string) YearMonth::current()];
    }

    public function data(array $filters): ?array
    {
        $from = $this->month($filters, 'from');
        $until = $this->month($filters, 'until');

        if ($from === null || $until === null) {
            return null;
        }

        $rows = $this->members->collectionSummary($from, $until);

        return [
            'rows' => $rows,
            'charged' => Money::sum(array_column($rows, 'charged')),
            'paid' => Money::sum(array_column($rows, 'paid')),
            'outstanding' => Money::sum(array_column($rows, 'outstanding')),
            'heading' => __('reports.collection.heading', ['from' => Display::yearMonth($from), 'until' => Display::yearMonth($until)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.collection-summary';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'collection-summary-'.($filters['from'] ?? '').'-'.($filters['until'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('dues.month'), __('reports.collection.members'), __('reports.collection.charged'), __('reports.collection.paid'), __('dues.outstanding')], bold: true);
        foreach ($data['rows'] as $row) {
            $book->row([(string) $row['month'], $row['members'], $row['charged'], $row['paid'], $row['outstanding']]);
        }
        $book->row([__('journal.line.total'), null, $data['charged'], $data['paid'], $data['outstanding']], bold: true);
    }
}
