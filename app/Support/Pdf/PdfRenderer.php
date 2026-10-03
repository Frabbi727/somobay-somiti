<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a Blade view to PDF with a Bangla-capable font and OpenType shaping, so
 * conjuncts (যুক্তাক্ষর) such as ক্ষ, ন্দ্র and র্ক are drawn correctly.
 */
final class PdfRenderer
{
    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data = [], string $orientation = 'P', string $title = ''): string
    {
        $mpdf = $this->make($orientation);
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function make(string $orientation): Mpdf
    {
        /** @var array<string, mixed> $config */
        $config = config('somiti.pdf');
        $family = (string) $config['font_family'];
        $tempDir = (string) $config['temp_dir'];

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        /** @var array{fontDir: list<string>} $defaults */
        $defaults = (new ConfigVariables)->getDefaults();
        /** @var array{fontdata: array<string, mixed>} $fontDefaults */
        $fontDefaults = (new FontVariables)->getDefaults();

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => $orientation,
            'tempDir' => $tempDir,
            'fontDir' => [...$defaults['fontDir'], (string) $config['font_dir']],
            'fontdata' => $fontDefaults['fontdata'] + [
                $family => [...(array) $config['font_files'], 'useOTL' => 0xFF],
            ],
            'default_font' => $family,
            // Tag scripts for OpenType shaping, but keep our font: mPDF's own language
            // fallback would swap Bangla to FreeSerif, which has no bold Bengali glyphs.
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
            // Symbols the Bangla font lacks (e.g. ↺ in reversal narrations) fall back to DejaVu.
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusanscondensed'],
            'defaultPageNumStyle' => app()->getLocale() === 'bn' ? 'bengali' : '1',
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 12,
            'margin_right' => 12,
        ]);
    }
}
