<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Schemas;

use App\Domain\Accounting\Models\FundTransfer;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

final class FundTransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('transfer_no')->label(__('transfers.field.number'))->weight('bold'),
                    TextEntry::make('status')->label(__('transfers.field.status'))->badge(),
                    TextEntry::make('amount_poisha')
                        ->label(__('transfers.field.amount'))
                        ->formatStateUsing(fn (FundTransfer $record): string => Display::money($record->amount_poisha))
                        ->weight('bold'),
                    TextEntry::make('from_method')->label(__('transfers.field.from'))->badge()->color('gray'),
                    TextEntry::make('to_method')->label(__('transfers.field.to'))->badge()->color('gray'),
                    TextEntry::make('charge_poisha')
                        ->label(__('transfers.field.charge'))
                        ->formatStateUsing(fn (FundTransfer $record): string => Display::money($record->charge_poisha)),
                    TextEntry::make('transferred_on')
                        ->label(__('transfers.field.transferred_on'))
                        ->formatStateUsing(fn (FundTransfer $record): string => Display::date($record->transferred_on)),
                    TextEntry::make('reference')->label(__('transfers.field.reference'))->placeholder('—'),
                    TextEntry::make('attachment_path')
                        ->label(__('transfers.field.attachment'))
                        ->formatStateUsing(fn (): string => __('common.view'))
                        ->url(fn (FundTransfer $record): ?string => $record->attachment_path === null ? null : Storage::disk('local')->temporaryUrl($record->attachment_path, now()->addMinutes(10)), shouldOpenInNewTab: true)
                        ->color('primary')
                        ->placeholder('—'),
                    TextEntry::make('notes')->label(__('transfers.field.notes'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('journalEntry.voucher_no')
                        ->label(__('transfers.field.voucher'))
                        ->formatStateUsing(fn (string $state): string => Display::digits($state))
                        ->url(fn (FundTransfer $record): ?string => $record->journal_entry_id === null ? null : JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]))
                        ->placeholder('—'),
                    TextEntry::make('recorder.name')->label(__('transfers.field.recorded_by')),
                    TextEntry::make('approver.name')->label(__('transfers.field.approved_by'))->placeholder('—'),
                    TextEntry::make('rejection_reason')->label(__('transfers.field.reason'))->visible(fn (FundTransfer $record): bool => $record->rejection_reason !== null),
                    TextEntry::make('reversal_reason')->label(__('transfers.field.reason'))->visible(fn (FundTransfer $record): bool => $record->reversal_reason !== null),
                ]),
        ]);
    }
}
