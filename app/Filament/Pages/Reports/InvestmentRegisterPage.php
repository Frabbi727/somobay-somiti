<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\InvestmentRegisterReport;

final class InvestmentRegisterPage extends ReportPage
{
    protected static ?string $slug = 'reports/investment-register';

    protected static ?int $navigationSort = 110;

    protected static function reportClass(): string
    {
        return InvestmentRegisterReport::class;
    }
}
