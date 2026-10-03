<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Reports\Definitions\ShareRegisterReport;

final class ShareRegisterPage extends ReportPage
{
    protected static ?string $slug = 'reports/share-register';

    protected static ?int $navigationSort = 100;

    protected static function reportClass(): string
    {
        return ShareRegisterReport::class;
    }
}
