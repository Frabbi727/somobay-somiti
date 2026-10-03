<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\DefaultersReport;

final class DefaultersPage extends ReportPage
{
    protected static ?string $slug = 'reports/defaulters';

    protected static ?int $navigationSort = 90;

    protected static function reportClass(): string
    {
        return DefaultersReport::class;
    }
}
