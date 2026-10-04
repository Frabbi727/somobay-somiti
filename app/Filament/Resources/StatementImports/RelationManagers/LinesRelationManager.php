<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\RelationManagers;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementLine;
use App\Filament\Resources\StatementImports\Actions\StatementLineActions;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('statements.field.lines');
    }

    /**
     * Matching is guarded by policies, so it stays available on the read-only view page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('journalLine.entry'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('transacted_on')->orderBy('line_no'))
            ->columns([
                TextColumn::make('transacted_on')
                    ->label(__('statements.field.date'))
                    ->formatStateUsing(fn (StatementLine $record): string => Display::date($record->transacted_on)),
                TextColumn::make('description')->label(__('statements.field.description'))->limit(40)->searchable(['description', 'reference'])->placeholder('—'),
                TextColumn::make('reference')->label(__('statements.field.reference'))->placeholder('—')->visibleFrom('md'),
                TextColumn::make('amount_poisha')
                    ->label(__('statements.field.amount'))
                    ->formatStateUsing(fn (StatementLine $record): string => Display::money($record->amount_poisha))
                    ->color(fn (StatementLine $record): string => $record->isInflow() ? 'success' : 'danger')
                    ->alignment(Alignment::End)
                    ->weight('bold'),
                TextColumn::make('balance_poisha')
                    ->label(__('statements.field.balance'))
                    ->formatStateUsing(fn (StatementLine $record): string => $record->balance_poisha === null ? '—' : Display::money($record->balance_poisha))
                    ->alignment(Alignment::End)
                    ->visibleFrom('lg'),
                TextColumn::make('status')->label(__('statements.field.status'))->badge(),
                TextColumn::make('journalLine.entry.voucher_no')
                    ->label(__('statements.field.matched_to'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->description(fn (StatementLine $record): ?string => $record->ignore_reason)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('statements.field.status'))->options(StatementLineStatus::class),
            ])
            ->headerActions([
                StatementLineActions::autoMatch()->record($this->getOwnerRecord()),
            ])
            ->recordActions([
                StatementLineActions::match()->iconButton(),
                StatementLineActions::ignore()->iconButton(),
                StatementLineActions::unmatch()->iconButton(),
            ]);
    }
}
