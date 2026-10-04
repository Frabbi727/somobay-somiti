<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Schemas;

use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentLedgerEntry;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use App\Support\Money\Bps;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

final class InvestmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('investment_no')->label(__('investments.field.number'))->weight('bold'),
                    TextEntry::make('status')->label(__('investments.field.status'))->badge(),
                    TextEntry::make('type')->label(__('investments.field.type'))->badge()->color('gray'),
                    TextEntry::make('institution')->label(__('investments.field.institution')),
                    TextEntry::make('instrument_no')->label(__('investments.field.instrument_no'))->placeholder('—'),
                    TextEntry::make('funded_from')->label(__('investments.field.funded_from'))->badge()->color('gray'),
                    TextEntry::make('principal_poisha')
                        ->label(__('investments.field.principal'))
                        ->formatStateUsing(fn (Investment $record): string => Display::money($record->principal_poisha)),
                    TextEntry::make('book_value')
                        ->label(__('investments.field.book_value'))
                        ->state(fn (Investment $record): string => Display::money($record->bookValue()))
                        ->weight('bold'),
                    TextEntry::make('expected_rate_bps')
                        ->label(__('investments.field.expected_rate'))
                        ->state(fn (Investment $record): ?string => $record->expected_rate_bps === null ? null : Bps::of($record->expected_rate_bps)->format(app()->getLocale()))
                        ->placeholder('—'),
                    TextEntry::make('invested_on')
                        ->label(__('investments.field.invested_on'))
                        ->formatStateUsing(fn (Investment $record): string => Display::date($record->invested_on)),
                    TextEntry::make('matures_on')
                        ->label(__('investments.field.matures_on'))
                        ->state(fn (Investment $record): ?string => $record->matures_on === null ? null : Display::date($record->matures_on))
                        ->placeholder('—'),
                    TextEntry::make('resolution.resolution_no')
                        ->label(__('investments.field.resolution'))
                        ->state(fn (Investment $record): ?string => $record->resolution?->displayName())
                        ->placeholder('—'),
                    TextEntry::make('limit_warnings')
                        ->label(__('investments.field.warnings'))
                        ->color('warning')
                        ->visible(fn (Investment $record): bool => $record->limit_warnings !== null)
                        ->columnSpanFull(),
                    TextEntry::make('notes')->label(__('investments.field.notes'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('attachment_path')
                        ->label(__('investments.field.attachment'))
                        ->formatStateUsing(fn (): string => __('common.view'))
                        ->url(fn (Investment $record): ?string => $record->attachment_path === null ? null : Storage::disk('local')->temporaryUrl($record->attachment_path, now()->addMinutes(10)), shouldOpenInNewTab: true)
                        ->color('primary')
                        ->placeholder('—'),
                    TextEntry::make('recorder.name')->label(__('investments.field.recorded_by')),
                    TextEntry::make('approver.name')->label(__('investments.field.approved_by'))->placeholder('—'),
                    TextEntry::make('rejection_reason')->label(__('investments.field.reason'))->visible(fn (Investment $record): bool => $record->rejection_reason !== null),
                ]),
            Section::make(__('investments.register'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('ledger')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('kind')->hiddenLabel(),
                            TextEntry::make('journalEntry.voucher_no')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (string $state): string => Display::digits($state))
                                ->url(fn (InvestmentLedgerEntry $record): string => JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id])),
                            TextEntry::make('journalEntry.entry_date')
                                ->hiddenLabel()
                                ->state(fn (InvestmentLedgerEntry $record): string => Display::date($record->journalEntry->entry_date)),
                            TextEntry::make('delta_poisha')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (InvestmentLedgerEntry $record): string => Display::money($record->delta_poisha)),
                        ])
                        ->placeholder('—'),
                ]),
        ]);
    }
}
