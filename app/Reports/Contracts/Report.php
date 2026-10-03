<?php

declare(strict_types=1);

namespace App\Reports\Contracts;

use App\Support\Spreadsheet\Workbook;
use Filament\Schemas\Components\Component;

/**
 * A printable register: filters, the data they select, an HTML table (shared by the screen and
 * the PDF) and an Excel layout.
 */
interface Report
{
    public function title(): string;

    /**
     * @return array<Component>
     */
    public function filters(): array;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * The data for the view, including a 'heading'; null while the filters are incomplete.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function data(array $filters): ?array;

    /**
     * Blade partial that renders the data as a table.
     *
     * @return view-string
     */
    public function view(): string;

    public function orientation(): string;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filename(array $filters): string;

    /**
     * @param  array<string, mixed>  $data
     */
    public function excel(Workbook $book, array $data): void;
}
