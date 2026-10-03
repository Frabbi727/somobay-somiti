<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\ReceiptsPaymentsReport;

final class ReceiptsPaymentsPage extends ReportPage
{
    protected static ?string $slug = 'reports/receipts-payments';

    protected static ?int $navigationSort = 50;

    protected static function reportClass(): string
    {
        return ReceiptsPaymentsReport::class;
    }
}
