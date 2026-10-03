<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Members\Models\Member;
use App\Domain\Reporting\MemberReports;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Spreadsheet\Workbook;
use Filament\Forms\Components\Select;

final class MemberStatementReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly MemberReports $members) {}

    public function title(): string
    {
        return __('reports.statement.title');
    }

    public function filters(): array
    {
        return [
            Select::make('member')
                ->label(__('payments.field.member'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Member::query()
                    ->where(fn ($query) => $query->where('member_no', 'ilike', "%{$search}%")->orWhere('name_en', 'ilike', "%{$search}%")->orWhere('name_bn', 'ilike', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"))
                    ->orderBy('member_no')->limit(20)->get()
                    ->mapWithKeys(fn (Member $member): array => [$member->id => $member->displayName()])->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Member::query()->find((int) $value)?->displayName() : null)
                ->live(),
            $this->dateField('from', __('reports.ledger.from')),
            $this->dateField('until', __('reports.ledger.until')),
        ];
    }

    public function defaults(): array
    {
        return ['member' => request()->integer('member') ?: null, 'from' => $this->fiscalYearStart(), 'until' => $this->today()];
    }

    public function data(array $filters): ?array
    {
        $member = is_numeric($filters['member'] ?? null) ? Member::query()->find((int) $filters['member']) : null;
        $from = $this->date($filters, 'from');
        $until = $this->date($filters, 'until');

        return $member === null || $from === null || $until === null ? null : [
            ...$this->members->statement($member, $from, $until),
            'member' => $member,
            'heading' => __('reports.statement.heading', ['member' => $member->displayName(), 'from' => Display::date($from), 'until' => Display::date($until)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.member-statement';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'member-statement-'.($filters['member'] ?? '').'-'.($filters['from'] ?? '').'-'.($filters['until'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('reports.ledger.date'), __('reports.ledger.narration'), __('reports.statement.charge'), __('reports.statement.paid'), __('reports.ledger.balance')], bold: true);
        $book->row([null, __('reports.ledger.opening'), null, null, $data['opening']]);
        foreach ($data['rows'] as $row) {
            $book->row([$row['date']->toDateString(), $row['description'], $row['charge'], $row['paid'], $row['balance']]);
        }
        $book->row([__('reports.ledger.closing'), null, $data['total_charges'], $data['total_paid'], $data['closing']], bold: true);
    }
}
