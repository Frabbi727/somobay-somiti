<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domain\Accounting\Reports\ControlCheck;
use App\Domain\Accounting\Reports\TrialBalanceReport;
use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Accounting\Services\TrialBalance;
use App\Filament\Support\Display;
use App\Support\Pdf\PdfRenderer;
use App\Support\Spreadsheet\Workbook;
use Carbon\CarbonImmutable;

/**
 * The trial balance with the control-account reconciliation, as PDF or Excel.
 */
final class TrialBalanceDocument
{
    public function __construct(
        private readonly TrialBalance $trialBalance,
        private readonly Reconciliation $reconciliation,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * @return array{report: TrialBalanceReport, checks: list<ControlCheck>, heading: string}
     */
    public function data(CarbonImmutable $asOf): array
    {
        return [
            'report' => $this->trialBalance->asOf($asOf),
            'checks' => $this->reconciliation->controlVsSubledger($asOf),
            'heading' => __('reports.trial_balance.heading', ['date' => Display::date($asOf)]),
        ];
    }

    public function filename(CarbonImmutable $asOf, string $extension): string
    {
        return 'trial-balance-'.$asOf->toDateString().'.'.$extension;
    }

    public function pdf(CarbonImmutable $asOf): string
    {
        $data = $this->data($asOf);

        return $this->pdf->render('reports.pdf.trial-balance', $data, 'P', $data['heading']);
    }

    public function excel(CarbonImmutable $asOf): string
    {
        ['report' => $report, 'checks' => $checks, 'heading' => $heading] = $this->data($asOf);
        $bangla = Display::isBangla();

        $book = (new Workbook(__('reports.trial_balance.title')))
            ->title([(string) config('app.name')])
            ->title([$heading])
            ->blank()
            ->row([
                __('reports.trial_balance.code'),
                __('reports.trial_balance.account'),
                __('reports.trial_balance.type'),
                __('reports.trial_balance.debit'),
                __('reports.trial_balance.credit'),
            ], bold: true);

        foreach ($report->balanceRows() as $row) {
            $book->row([
                $row->account->code,
                $bangla ? $row->account->name_bn : $row->account->name_en,
                $row->account->type->getLabel(),
                $row->debitBalance(),
                $row->creditBalance(),
            ]);
        }

        $book->row([__('reports.trial_balance.total'), null, null, $report->totalDebit(), $report->totalCredit()], bold: true)
            ->blank()
            ->title([__('reports.reconciliation.title')])
            ->row([
                __('reports.reconciliation.account'),
                __('reports.reconciliation.source'),
                __('reports.reconciliation.general_ledger'),
                __('reports.reconciliation.subledger'),
                __('reports.reconciliation.difference'),
            ], bold: true);

        foreach ($checks as $check) {
            $book->row([
                $check->account->code.' · '.($bangla ? $check->account->name_bn : $check->account->name_en),
                $check->source,
                $check->generalLedger,
                $check->subledger,
                $check->difference(),
            ]);
        }

        return $book->toBinary();
    }
}
