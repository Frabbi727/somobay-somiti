<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Models\Account;
use App\Domain\Reporting\CashMovements;
use App\Reports\Contracts\Report;
use App\Reports\LedgerDocument;
use App\Support\Spreadsheet\Workbook;
use Filament\Forms\Components\Select;

/**
 * The ledger of one cash-like account (cash in hand by default).
 */
final class CashBookReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly LedgerDocument $ledger) {}

    public function title(): string
    {
        return __('reports.cash_book.title');
    }

    public function filters(): array
    {
        return [
            Select::make('account')
                ->label(__('reports.ledger.account'))
                ->options(fn (): array => Account::query()->whereIn('code', CashMovements::CASH_CODES)->orderBy('code')->get()
                    ->mapWithKeys(fn (Account $account): array => [$account->code => $account->displayName()])->all())
                ->required()
                ->live(),
            $this->dateField('from', __('reports.ledger.from')),
            $this->dateField('until', __('reports.ledger.until')),
        ];
    }

    public function defaults(): array
    {
        return ['account' => AccountCode::CASH, 'from' => $this->fiscalYearStart(), 'until' => $this->today()];
    }

    public function data(array $filters): ?array
    {
        $account = Account::query()->whereIn('code', CashMovements::CASH_CODES)->where('code', (string) ($filters['account'] ?? ''))->first();
        $from = $this->date($filters, 'from');
        $until = $this->date($filters, 'until');

        return $account === null || $from === null || $until === null ? null : $this->ledger->data($account, $from, $until, null);
    }

    public function view(): string
    {
        return 'reports.partials.ledger';
    }

    public function orientation(): string
    {
        return 'L';
    }

    public function filename(array $filters): string
    {
        return 'cash-book-'.($filters['account'] ?? '').'-'.($filters['from'] ?? '').'-'.($filters['until'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $report = $data['report'];
        $book->row([__('reports.ledger.date'), __('reports.ledger.voucher'), __('reports.ledger.narration'), __('reports.ledger.debit'), __('reports.ledger.credit'), __('reports.ledger.balance')], bold: true);
        $book->row([null, null, __('reports.ledger.opening'), null, null, $report->opening]);
        foreach ($report->rows as $row) {
            $book->row([$row->date->toDateString(), $row->voucherNo, $row->narration, $row->debit, $row->credit, $row->balance]);
        }
        $book->row([__('reports.ledger.closing'), null, null, $report->totalDebit(), $report->totalCredit(), $report->closing()], bold: true);
    }
}
