<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Domain\Accounting\Models\FiscalYear;
use App\Filament\Resources\FiscalYears\Actions\FiscalYearActions;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Filament\Support\Display;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property FiscalYear $record
 */
final class ViewFiscalYear extends ViewRecord
{
    protected static string $resource = FiscalYearResource::class;

    public function getTitle(): string
    {
        return __('accounting.fiscal_year.singular').' '.Display::digits($this->record->code);
    }

    protected function getHeaderActions(): array
    {
        return [
            FiscalYearActions::close(),
        ];
    }
}
