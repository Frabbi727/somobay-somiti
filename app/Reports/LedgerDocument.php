<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Reports\LedgerReport;
use App\Domain\Accounting\Services\LedgerQuery;
use App\Domain\Settings\Models\SomitiProfile;
use App\Filament\Support\Display;
use App\Support\Pdf\PdfRenderer;
use App\Support\Spreadsheet\Workbook;
use Carbon\CarbonImmutable;

final class LedgerDocument
{
    public function __construct(
        private readonly LedgerQuery $ledger,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * @return array{report: LedgerReport, heading: string}
     */
    public function data(Account $account, CarbonImmutable $from, CarbonImmutable $until, ?int $memberId): array
    {
        $report = $this->ledger->forAccount($account, $from, $until, $memberId);

        $heading = __('reports.ledger.heading', [
            'account' => Display::digits($account->code).' · '.(Display::isBangla() ? $account->name_bn : $account->name_en),
            'from' => Display::date($from),
            'until' => Display::date($until),
        ]);

        if ($memberId !== null) {
            $heading .= ' · '.__('reports.ledger.member_heading', ['member' => Display::digits($memberId)]);
        }

        return ['report' => $report, 'heading' => $heading];
    }

    public function filename(Account $account, CarbonImmutable $from, CarbonImmutable $until, string $extension): string
    {
        return sprintf('ledger-%s-%s-%s.%s', $account->code, $from->toDateString(), $until->toDateString(), $extension);
    }

    public function pdf(Account $account, CarbonImmutable $from, CarbonImmutable $until, ?int $memberId): string
    {
        $data = $this->data($account, $from, $until, $memberId);

        return $this->pdf->render('reports.pdf.ledger', $data, 'L', $data['heading']);
    }

    public function excel(Account $account, CarbonImmutable $from, CarbonImmutable $until, ?int $memberId): string
    {
        ['report' => $report, 'heading' => $heading] = $this->data($account, $from, $until, $memberId);

        $book = (new Workbook(__('reports.ledger.title')))
            ->title([SomitiProfile::current()->displayName()])
            ->title([$heading])
            ->blank()
            ->row([
                __('reports.ledger.date'),
                __('reports.ledger.voucher'),
                __('reports.ledger.narration'),
                __('reports.ledger.member'),
                __('reports.ledger.debit'),
                __('reports.ledger.credit'),
                __('reports.ledger.balance'),
            ], bold: true)
            ->row([$from->toDateString(), null, __('reports.ledger.opening'), null, null, null, $report->opening]);

        foreach ($report->rows as $row) {
            $book->row([
                $row->date->toDateString(),
                $row->voucherNo,
                $row->memo === null ? $row->narration : $row->narration.' — '.$row->memo,
                $row->memberId,
                $row->debit,
                $row->credit,
                $row->balance,
            ]);
        }

        return $book
            ->row([__('reports.ledger.closing'), null, null, null, $report->totalDebit(), $report->totalCredit(), $report->closing()], bold: true)
            ->toBinary();
    }
}
