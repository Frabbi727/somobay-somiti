<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dues\Pages;

use App\Filament\Pages\Dues\GenerateDues;
use App\Filament\Resources\Dues\Actions\DueActions;
use App\Filament\Resources\Dues\DueResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListDues extends ListRecords
{
    protected static string $resource = DueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DueActions::applyLateFees(),
            Action::make('generate')
                ->label(__('dues.generate.title'))
                ->tooltip(__('dues.generate.title'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->url(GenerateDues::getUrl())
                ->visible(fn (): bool => GenerateDues::canAccess())
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
