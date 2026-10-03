<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Reporting\FinancialStatements;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Spreadsheet\Workbook;

final class IncomeStatementReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly FinancialStatements $statements) {}

    public function title(): string
    {
        return __('reports.income.title');
    }

    public function filters(): array
    {
        return [$this->dateField('from', __('reports.ledger.from')), $this->dateField('until', __('reports.ledger.until'))];
    }

    public function defaults(): array
    {
        return ['from' => $this->fiscalYearStart(), 'until' => $this->today()];
    }

    public function data(array $filters): ?array
    {
        $from = $this->date($filters, 'from');
        $until = $this->date($filters, 'until');

        if ($from === null || $until === null) {
            return null;
        }

        return [
            ...$this->statements->incomeStatement($from, $until),
            'heading' => __('reports.income.heading', ['from' => Display::date($from), 'until' => Display::date($until)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.income-statement';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'income-statement-'.($filters['from'] ?? '').'-'.($filters['until'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('reports.income.income')], bold: true);
        foreach ($data['income'] as $row) {
            $book->row([$row['account']->code, $row['account']->displayName(), $row['amount']]);
        }
        $book->row([__('reports.income.total_income'), null, $data['total_income']], bold: true)->blank();
        $book->row([__('reports.income.expense')], bold: true);
        foreach ($data['expense'] as $row) {
            $book->row([$row['account']->code, $row['account']->displayName(), $row['amount']]);
        }
        $book->row([__('reports.income.total_expense'), null, $data['total_expense']], bold: true)->blank();
        $book->row([__('reports.income.surplus'), null, $data['surplus']], bold: true);
    }
}
