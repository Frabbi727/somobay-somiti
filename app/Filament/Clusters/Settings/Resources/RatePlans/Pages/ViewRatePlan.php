<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Pages;

use App\Domain\Settings\Models\RatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Actions\RatePlanActions;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Support\Display;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Approved plans are view-only here; the only ways forward are duplicating or cancelling.
 *
 * @property RatePlan $record
 */
final class ViewRatePlan extends ViewRecord
{
    protected static string $resource = RatePlanResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['approvals.user', 'creator']);
    }

    public function getTitle(): string
    {
        return $this->record->code.' · '.Display::yearMonth($this->record->effective_from);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->icon(Heroicon::OutlinedPencilSquare)->color('warning'),
            RatePlanActions::submit(),
            RatePlanActions::approve(),
            RatePlanActions::reject(),
            RatePlanActions::downloadImpact(),
            RatePlanActions::duplicate(),
            RatePlanActions::cancel(),
            RatePlanActions::delete(),
        ];
    }
}
