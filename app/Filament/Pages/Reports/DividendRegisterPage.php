<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\DividendRegisterReport;

final class DividendRegisterPage extends ReportPage
{
    protected static ?string $slug = 'reports/dividend-register';

    protected static ?int $navigationSort = 120;

    protected static function reportClass(): string
    {
        return DividendRegisterReport::class;
    }
}
