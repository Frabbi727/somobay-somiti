<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts\Pages;

use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListJournalDrafts extends ListRecords
{
    protected static string $resource = JournalDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('journal.actions.create_draft'))
                ->icon(Heroicon::OutlinedPlus)
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
