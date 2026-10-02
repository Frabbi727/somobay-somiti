<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Domain\Accounting\Models\JournalEntry;
use App\Filament\Resources\JournalEntries\Actions\ReverseJournalAction;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property JournalEntry $record
 */
final class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    public function getTitle(): string
    {
        return __('journal.voucher.singular').' '.Display::digits($this->record->voucher_no);
    }

    protected function getHeaderActions(): array
    {
        return [
            ReverseJournalAction::make(),
        ];
    }
}
