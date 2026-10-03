<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Pages;

use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListRatePlans extends ListRecords
{
    protected static string $resource = RatePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('rates.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
