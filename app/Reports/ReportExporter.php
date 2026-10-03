<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contracts\Report;
use App\Support\Pdf\PdfRenderer;
use App\Support\Spreadsheet\Workbook;

/**
 * Renders any Report as PDF (Bangla font, shared layout) or Excel.
 */
final class ReportExporter
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function pdf(Report $report, array $filters): ?string
    {
        $data = $report->data($filters);

        return $data === null ? null : $this->pdf->render('reports.pdf.generic', [
            ...$data,
            'partial' => $report->view(),
        ], $report->orientation(), (string) ($data['heading'] ?? $report->title()));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function excel(Report $report, array $filters): ?string
    {
        $data = $report->data($filters);

        if ($data === null) {
            return null;
        }

        $book = (new Workbook($report->title()))
            ->title([(string) config('app.name')])
            ->title([(string) ($data['heading'] ?? $report->title())])
            ->blank();

        $report->excel($book, $data);

        return $book->toBinary();
    }
}
