<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\MemberStatementReport;

final class MemberStatementPage extends ReportPage
{
    protected static ?string $slug = 'reports/member-statement';

    protected static ?int $navigationSort = 10;

    protected static function reportClass(): string
    {
        return MemberStatementReport::class;
    }
}
