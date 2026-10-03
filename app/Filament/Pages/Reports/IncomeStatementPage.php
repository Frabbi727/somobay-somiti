<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\IncomeStatementReport;

final class IncomeStatementPage extends ReportPage
{
    protected static ?string $slug = 'reports/income-statement';

    protected static ?int $navigationSort = 60;

    protected static function reportClass(): string
    {
        return IncomeStatementReport::class;
    }
}
