<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\BalanceSheetReport;

final class BalanceSheetPage extends ReportPage
{
    protected static ?string $slug = 'reports/balance-sheet';

    protected static ?int $navigationSort = 70;

    protected static function reportClass(): string
    {
        return BalanceSheetReport::class;
    }
}
