<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Services\ExpectedReturns;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Money\Money;
use App\Support\Spreadsheet\Workbook;

/**
 * T-12: for every investment with an expected return, what it should have paid by a date and what
 * was received. Tracking only — the books record income when it arrives.
 */
final class ExpectedReturnsReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly ExpectedReturns $returns) {}

    public function title(): string
    {
        return __('investments.returns.report.title');
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

        if ($asOf === null) {
            return null;
        }

        $investments = Investment::query()
            ->whereIn('status', [InvestmentStatus::Active, InvestmentStatus::Closed])
            ->where('invested_on', '<=', $asOf->toDateString())
            ->whereHas('returnExpectations')
            ->with('returnExpectations')
            ->orderBy('investment_no')
            ->get();

        $rows = $investments->map(fn (Investment $investment): array => ['investment' => $investment, ...$this->returns->summary($investment, $asOf)])->all();

        return [
            'rows' => $rows,
            'expected' => Money::sum(array_map(fn (array $row): Money => $row['expected'], $rows)),
            'received' => Money::sum(array_map(fn (array $row): Money => $row['received'], $rows)),
            'difference' => Money::sum(array_map(fn (array $row): Money => $row['difference'], $rows)),
            'heading' => __('investments.returns.report.heading', ['date' => Display::date($asOf)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.expected-returns';
    }

    public function orientation(): string
    {
        return 'L';
    }

    public function filename(array $filters): string
    {
        return 'expected-returns-'.($filters['as_of'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([
            __('investments.field.number'), __('investments.field.name'), __('investments.returns.field.frequency'), __('investments.returns.field.amount'),
            __('investments.returns.field.expected_to_date'), __('investments.returns.field.received'), __('investments.returns.field.difference'),
            __('investments.returns.field.last_received'), __('investments.returns.field.next_due'), __('investments.field.status'),
        ], bold: true);

        foreach ($data['rows'] as $row) {
            $investment = $row['investment'];
            $book->row([
                $investment->investment_no, $investment->displayName(), $row['current']?->frequency->getLabel(), $row['current']?->amount_poisha,
                $row['expected'], $row['received'], $row['difference'],
                $row['last_received_on']?->toDateString(), $row['next_due'] === null ? null : $row['next_due']['due_on']->toDateString(), $investment->status->getLabel(),
            ]);
        }

        $book->row([__('journal.line.total'), null, null, null, $data['expected'], $data['received'], $data['difference'], null, null, null], bold: true);
    }
}
