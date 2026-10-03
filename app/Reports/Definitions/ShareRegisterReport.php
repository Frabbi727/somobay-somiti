<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Reporting\MemberReports;
use App\Filament\Support\Display;
use App\Reports\Contracts\Report;
use App\Support\Spreadsheet\Workbook;
use App\Support\Time\YearMonth;

final class ShareRegisterReport implements Report
{
    use ReadsFilters;

    public function __construct(private readonly MemberReports $members) {}

    public function title(): string
    {
        return __('reports.shares.title');
    }

    public function filters(): array
    {
        return [$this->monthField('month', __('dues.month'))];
    }

    public function defaults(): array
    {
        return ['month' => (string) YearMonth::current()];
    }

    public function data(array $filters): ?array
    {
        $month = $this->month($filters, 'month');

        if ($month === null) {
            return null;
        }

        $rows = $this->members->shareRegister($month);

        return [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'shares')),
            'heading' => __('reports.shares.heading', ['month' => Display::yearMonth($month)]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.share-register';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'share-register-'.($filters['month'] ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('members.member.member_no'), __('members.member.name'), __('members.member.joined_on'), __('members.member.status'), __('members.member.shares')], bold: true);
        foreach ($data['rows'] as $row) {
            $member = $row['member'];
            $book->row([$member->member_no, $member->name_bn.' / '.$member->name_en, $member->joined_on->toDateString(), $member->status->getLabel(), $row['shares']]);
        }
        $book->row([__('journal.line.total'), null, null, null, $data['total']], bold: true);
    }
}
