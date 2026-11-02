<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\ExpectedReturnsReport;

final class ExpectedReturnsPage extends ReportPage
{
    protected static ?string $slug = 'reports/expected-returns';

    protected static ?int $navigationSort = 111;

    protected static function reportClass(): string
    {
        return ExpectedReturnsReport::class;
    }
}
