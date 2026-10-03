<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Domain\Accounting\Models\Expense;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

final class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('expense_no')->label(__('expenses.field.number'))->weight('bold'),
                    TextEntry::make('status')->label(__('expenses.field.status'))->badge(),
                    TextEntry::make('amount_poisha')
                        ->label(__('expenses.field.amount'))
                        ->formatStateUsing(fn (Expense $record): string => Display::money($record->amount_poisha))
                        ->weight('bold'),
                    TextEntry::make('account.name_en')
                        ->label(__('expenses.field.category'))
                        ->formatStateUsing(fn (Expense $record): string => $record->account->displayName()),
                    TextEntry::make('paid_from')->label(__('expenses.field.paid_from'))->badge()->color('gray'),
                    TextEntry::make('spent_on')
                        ->label(__('expenses.field.spent_on'))
                        ->formatStateUsing(fn (Expense $record): string => Display::date($record->spent_on)),
                    TextEntry::make('payee')->label(__('expenses.field.payee'))->placeholder('—'),
                    TextEntry::make('reference')->label(__('expenses.field.reference'))->placeholder('—'),
                    TextEntry::make('needs_president')
                        ->label(__('expenses.field.needs_president'))
                        ->state(fn (Expense $record): string => __($record->needsPresident() ? 'common.yes' : 'common.no')),
                    TextEntry::make('description')->label(__('expenses.field.description'))->columnSpanFull(),
                    TextEntry::make('attachment_path')
                        ->label(__('expenses.field.attachment'))
                        ->formatStateUsing(fn (): string => __('common.view'))
                        ->url(fn (Expense $record): ?string => $record->attachment_path === null ? null : Storage::disk('local')->temporaryUrl($record->attachment_path, now()->addMinutes(10)), shouldOpenInNewTab: true)
                        ->color('primary')
                        ->placeholder('—'),
                    TextEntry::make('journalEntry.voucher_no')
                        ->label(__('expenses.field.voucher'))
                        ->formatStateUsing(fn (string $state): string => Display::digits($state))
                        ->url(fn (Expense $record): ?string => $record->journal_entry_id === null ? null : JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]))
                        ->placeholder('—'),
                    TextEntry::make('recorder.name')->label(__('expenses.field.recorded_by')),
                    TextEntry::make('approver.name')->label(__('expenses.field.approved_by'))->placeholder('—'),
                    TextEntry::make('rejection_reason')->label(__('expenses.field.reason'))->visible(fn (Expense $record): bool => $record->rejection_reason !== null),
                    TextEntry::make('reversal_reason')->label(__('expenses.field.reason'))->visible(fn (Expense $record): bool => $record->reversal_reason !== null),
                ]),
        ]);
    }
}
