<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\CashBookReport;

final class CashBookPage extends ReportPage
{
    protected static ?string $slug = 'reports/cash-book';

    protected static ?int $navigationSort = 40;

    protected static function reportClass(): string
    {
        return CashBookReport::class;
    }
}
