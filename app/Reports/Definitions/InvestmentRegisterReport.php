<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Investments\Services\InvestmentRegister;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Spreadsheet\Workbook;

final class InvestmentRegisterReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly InvestmentRegister $register) {}

    public function title(): string
    {
        return __('investments.report.title');
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

        return [
            ...$this->register->asOf($asOf),
            'heading' => __('investments.report.heading', ['date' => Display::date($asOf)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.investment-register';
    }

    public function orientation(): string
    {
        return 'L';
    }

    public function filename(array $filters): string
    {
        return 'investment-register-'.($filters['as_of'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([
            __('investments.field.number'), __('investments.field.type'), __('investments.field.institution'), __('investments.field.instrument_no'),
            __('investments.field.invested_on'), __('investments.field.matures_on'), __('investments.field.principal'), __('investments.field.book_value'),
            __('investments.report.income'), __('investments.field.status'),
        ], bold: true);

        foreach ($data['rows'] as $row) {
            $investment = $row['investment'];
            $book->row([
                $investment->investment_no, $investment->type->getLabel(), $investment->institution, $investment->instrument_no,
                $investment->invested_on->toDateString(), $investment->matures_on?->toDateString(), $investment->principal_poisha,
                $row['book'], $row['income'], $investment->status->getLabel(),
            ]);
        }

        $book->row([__('journal.line.total'), null, null, null, null, null, null, $data['total'], $data['income'], null], bold: true);
        $book->blank();
        $book->row([__('investments.field.type'), __('investments.report.register_total'), __('investments.report.ledger_total')], bold: true);

        foreach ($data['groups'] as $group) {
            $book->row([$group['type']->getLabel(), $group['book'], $group['ledger']]);
        }
    }
}
