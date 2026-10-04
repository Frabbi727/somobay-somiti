<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Domain\YearEnd\Models\YearEnd;
use App\Reports\Contracts\Report;
use App\Support\Money\Money;
use App\Support\Spreadsheet\Workbook;
use Filament\Forms\Components\Select;

final class DividendRegisterReport implements Report
{
    public function title(): string
    {
        return __('year_end.dividend_register');
    }

    public function filters(): array
    {
        return [
            Select::make('year_end')
                ->label(__('year_end.wizard.fiscal_year'))
                ->options(fn (): array => YearEnd::query()->where('status', YearEndStatus::Posted)->orderByDesc('id')->get()
                    ->mapWithKeys(fn (YearEnd $yearEnd): array => [$yearEnd->id => $yearEnd->fiscalYear->code])->all())
                ->native(false)
                ->live(),
        ];
    }

    public function defaults(): array
    {
        return ['year_end' => YearEnd::query()->where('status', YearEndStatus::Posted)->orderByDesc('id')->value('id')];
    }

    public function data(array $filters): ?array
    {
        $yearEnd = is_numeric($filters['year_end'] ?? null) ? YearEnd::query()->find((int) $filters['year_end']) : null;

        if ($yearEnd === null) {
            return null;
        }

        $lines = $yearEnd->dividendLines()->with('member')->orderBy('member_id')->get();

        return [
            'lines' => $lines,
            'total' => Money::sum($lines->map(fn (DividendLine $line): Money => $line->amount_poisha)),
            'share_months' => (int) $lines->sum('share_months'),
            'heading' => __('year_end.report.heading', ['code' => $yearEnd->fiscalYear->code]),
        ];
    }

    public function view(): string
    {
        return 'reports.partials.dividend-register';
    }

    public function orientation(): string
    {
        return 'P';
    }

    public function filename(array $filters): string
    {
        return 'dividend-register-'.(YearEnd::query()->find((int) ($filters['year_end'] ?? 0))?->fiscalYear->code ?? '');
    }

    public function excel(Workbook $book, array $data): void
    {
        $book->row([__('members.member.member_no'), __('members.member.name'), __('year_end.field.share_months'), __('year_end.field.dividend'), __('year_end.field.status')], bold: true);

        foreach ($data['lines'] as $line) {
            $book->row([$line->member->member_no, $line->member->name_bn.' / '.$line->member->name_en, $line->share_months, $line->amount_poisha, $line->status->getLabel()]);
        }

        $book->row([__('journal.line.total'), null, $data['share_months'], $data['total'], null], bold: true);
    }
}
