<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts\Tables;

use App\Domain\Accounting\Models\JournalDraft;
use App\Filament\Resources\JournalDrafts\Actions\JournalDraftActions;
use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class JournalDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['creator', 'journalEntry']))
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (JournalDraft $record): string => $record->journalEntry !== null
                ? JournalEntryResource::getUrl('view', ['record' => $record->journalEntry])
                : JournalDraftResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label(__('journal.draft.number'))
                    ->formatStateUsing(fn (int $state): string => '#'.Display::digits($state))
                    ->sortable(),
                TextColumn::make('voucher_type')->label(__('journal.voucher.type'))->badge(),
                TextColumn::make('entry_date')
                    ->label(__('journal.voucher.entry_date'))
                    ->formatStateUsing(fn (JournalDraft $record): string => Display::date($record->entry_date))
                    ->sortable(),
                TextColumn::make('narration')
                    ->label(__('journal.voucher.narration'))
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('total')
                    ->label(__('journal.voucher.amount'))
                    ->state(fn (JournalDraft $record): string => Display::money($record->totalDebit()))
                    ->alignment(Alignment::End),
                TextColumn::make('status')
                    ->label(__('journal.draft.status'))
                    ->badge()
                    ->state(fn (JournalDraft $record): string => $record->journalEntry === null
                        ? __('journal.draft.status_draft')
                        : __('journal.draft.status_posted', ['voucher' => Display::digits($record->journalEntry->voucher_no)]))
                    ->color(fn (JournalDraft $record): string => $record->isPosted() ? 'success' : 'warning'),
                TextColumn::make('creator.name')
                    ->label(__('journal.draft.created_by'))
                    ->visibleFrom('lg'),
            ])
            ->filters([
                TernaryFilter::make('posted')
                    ->label(__('journal.voucher.state_posted'))
                    ->nullable()
                    ->attribute('journal_entry_id'),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip(__('common.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('warning'),
                JournalDraftActions::post()->iconButton(),
                ActionGroup::make([
                    JournalDraftActions::delete(),
                ])
                    ->iconButton()
                    ->tooltip(__('common.more')),
            ])
            ->emptyStateHeading(__('journal.draft.empty_heading'))
            ->emptyStateDescription(__('journal.draft.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentText);
    }
}
