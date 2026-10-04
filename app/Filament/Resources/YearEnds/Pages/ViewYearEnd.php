<?php

declare(strict_types=1);

namespace App\Filament\Resources\YearEnds\Pages;

use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Resources\YearEnds\Actions\YearEndActions;
use App\Filament\Resources\YearEnds\YearEndResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property YearEnd $record
 */
final class ViewYearEnd extends ViewRecord
{
    protected static string $resource = YearEndResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['fiscalYear', 'preparer', 'resolution']);
    }

    public function getTitle(): string
    {
        return __('year_end.singular').' · '.$this->record->fiscalYear->code;
    }

    protected function getHeaderActions(): array
    {
        return [YearEndActions::approve()];
    }
}
