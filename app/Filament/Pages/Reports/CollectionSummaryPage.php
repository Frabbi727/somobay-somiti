<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\CollectionSummaryReport;

final class CollectionSummaryPage extends ReportPage
{
    protected static ?string $slug = 'reports/collection-summary';

    protected static ?int $navigationSort = 80;

    protected static function reportClass(): string
    {
        return CollectionSummaryReport::class;
    }
}
