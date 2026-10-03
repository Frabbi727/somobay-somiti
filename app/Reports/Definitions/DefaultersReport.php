<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Reporting\MemberReports;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Money\Money;
use App\Support\Spreadsheet\Workbook;
use Filament\Forms\Components\TextInput;

final class DefaultersReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly MemberReports $members) {}

    public function title(): string
    {
        return __('reports.defaulters.title');
    }

    public function filters(): array
    {
        return [
            $this->dateField('as_of', __('reports.trial_balance.as_of')),
            TextInput::make('min_months')->label(__('reports.defaulters.min_months'))->integer()->minValue(1)->default(1)->required()->live(onBlur: true),
        ];
    }

    public function defaults(): array
    {
        return ['as_of' => $this->today(), 'min_months' => 1];
    }

    public function data(array $filters): ?array
    {
        $asOf = $this->date($filters, 'as_of');

        if ($asOf === null) {
            return null;
        }

        $rows = $this->members->defaulters($asOf, max(1, (int) ($filters['min_months'] ?? 1)));

        return [
            'rows' => $rows,
            'total' => Money::sum(array_column($rows, 'outstanding')),
            'heading' => __('reports.defaulters.heading', ['date' => Display::date($asOf)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.defaulters';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'defaulters-'.($filters['as_of'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('payments.field.member'), __('members.member.mobile'), __('reports.defaulters.months'), __('reports.defaulters.oldest'), __('dues.outstanding')], bold: true);
        foreach ($data['rows'] as $row) {
            $book->row([$row['member']->displayName(), $row['member']->mobile, $row['months'], (string) $row['oldest'], $row['outstanding']]);
        }
        $book->row([__('journal.line.total'), null, null, null, $data['total']], bold: true);
    }
}
