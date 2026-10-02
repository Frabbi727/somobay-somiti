<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Schemas;

use App\Domain\Accounting\Models\JournalEntry;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class JournalEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('voucher_no')
                            ->label(__('journal.voucher.voucher_no'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state))
                            ->weight('bold')
                            ->copyable()
                            ->copyableState(fn (JournalEntry $record): string => $record->voucher_no),
                        TextEntry::make('voucher_type')->label(__('journal.voucher.type'))->badge(),
                        TextEntry::make('entry_date')
                            ->label(__('journal.voucher.entry_date'))
                            ->state(fn (JournalEntry $record): string => Display::date($record->entry_date)),
                        TextEntry::make('fiscalYear.code')
                            ->label(__('journal.voucher.fiscal_year'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state)),
                        TextEntry::make('narration')
                            ->label(__('journal.voucher.narration'))
                            ->columnSpanFull(),
                        TextEntry::make('reverses.voucher_no')
                            ->label(__('journal.voucher.reverses'))
                            ->url(fn (JournalEntry $record): ?string => $record->reverses_id === null ? null : JournalEntryResource::getUrl('view', ['record' => $record->reverses_id]))
                            ->color('primary')
                            ->visible(fn (JournalEntry $record): bool => $record->isReversal()),
                        TextEntry::make('reason')
                            ->label(__('journal.voucher.reason'))
                            ->visible(fn (JournalEntry $record): bool => $record->isReversal())
                            ->columnSpan(3),
                        TextEntry::make('reversal.voucher_no')
                            ->label(__('journal.voucher.reversed_by'))
                            ->url(fn (JournalEntry $record): ?string => $record->reversal === null ? null : JournalEntryResource::getUrl('view', ['record' => $record->reversal]))
                            ->color('danger')
                            ->visible(fn (JournalEntry $record): bool => $record->reversal !== null),
                        TextEntry::make('postedBy.name')->label(__('journal.voucher.posted_by')),
                        TextEntry::make('posted_at')
                            ->label(__('journal.voucher.posted_at'))
                            ->state(fn (JournalEntry $record): string => Display::dateTime($record->posted_at)),
                    ]),
                Section::make(__('journal.voucher.lines'))
                    ->columnSpanFull()
                    ->schema([
                        ViewEntry::make('lines')
                            ->hiddenLabel()
                            ->view('filament.journal.lines'),
                    ]),
                Section::make(__('journal.voucher.hash'))
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('hash')
                            ->hiddenLabel()
                            ->fontFamily('mono')
                            ->copyable(),
                    ]),
            ]);
    }
}
