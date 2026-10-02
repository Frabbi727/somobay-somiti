<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Resources\FiscalYears\Actions\FiscalYearActions;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Resources\Pages\ListRecords;

final class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FiscalYearActions::openPrevious(),
            FiscalYearActions::openNext(),
        ];
    }
}
