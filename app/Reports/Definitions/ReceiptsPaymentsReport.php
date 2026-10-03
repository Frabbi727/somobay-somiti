<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Reporting\CashMovements;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Spreadsheet\Workbook;

final class ReceiptsPaymentsReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly CashMovements $cash) {}

    public function title(): string
    {
        return __('reports.receipts_payments.title');
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

        return $from === null || $until === null ? null : [
            ...$this->cash->receiptsAndPayments($from, $until),
            'heading' => __('reports.receipts_payments.heading', ['from' => Display::date($from), 'until' => Display::date($until)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.receipts-payments';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'receipts-payments-'.($filters['from'] ?? '').'-'.($filters['until'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('reports.receipts_payments.opening'), null, $data['opening']], bold: true);
        $book->row([__('reports.receipts_payments.receipts')], bold: true);
        foreach ($data['receipts'] as $row) {
            $book->row([$row['account']->code, $row['account']->displayName(), $row['amount']]);
        }
        $book->row([__('reports.receipts_payments.total_receipts'), null, $data['total_receipts']], bold: true);
        $book->row([__('reports.receipts_payments.payments')], bold: true);
        foreach ($data['payments'] as $row) {
            $book->row([$row['account']->code, $row['account']->displayName(), $row['amount']]);
        }
        $book->row([__('reports.receipts_payments.total_payments'), null, $data['total_payments']], bold: true);
        $book->row([__('reports.receipts_payments.closing'), null, $data['closing']], bold: true);
    }
}
