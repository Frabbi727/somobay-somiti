<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newVoucher')
                ->label(__('journal.actions.create_draft'))
                ->tooltip(__('journal.actions.create_draft'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->url(JournalDraftResource::getUrl('create'))
                ->visible(fn (): bool => JournalDraftResource::canCreate())
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
