<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Reporting\FinancialStatements;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Spreadsheet\Workbook;

final class BalanceSheetReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly FinancialStatements $statements) {}

    public function title(): string
    {
        return __('reports.balance.title');
    }

    public function filters(): array
    {
        return [$this->dateField('as_of', __('reports.trial_balance.as_of'))];
    }

    public function defaults(): array
    {
        return ['as_of' => $this->today()];
    }

    public function data(array $filters): ?array
    {
        $asOf = $this->date($filters, 'as_of');

        return $asOf === null ? null : [
            ...$this->statements->balanceSheet($asOf),
            'heading' => __('reports.balance.heading', ['date' => Display::date($asOf)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.balance-sheet';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'balance-sheet-'.($filters['as_of'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        foreach (['assets', 'liabilities', 'equity'] as $section) {
            $book->row([__('reports.balance.'.$section)], bold: true);
            foreach ($data[$section] as $row) {
                $book->row([$row['account']->code, $row['account']->displayName(), $row['amount']]);
            }
        }
        $book->row([null, __('reports.balance.surplus'), $data['surplus']])->blank();
        $book->row([__('reports.balance.total_assets'), null, $data['total_assets']], bold: true);
        $book->row([__('reports.balance.total_liabilities_equity'), null, $data['total_liabilities_equity']], bold: true);
    }
}
