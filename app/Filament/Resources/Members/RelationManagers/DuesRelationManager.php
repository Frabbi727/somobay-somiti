<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\RelationManagers;

use App\Domain\Contributions\Models\Due;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only list of the member's dues; collections arrive in Phase 5.
 */
final class DuesRelationManager extends RelationManager
{
    protected static string $relationship = 'dues';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('dues.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ratePlan'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('month')->orderBy('id'))
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('month')
                    ->label(__('dues.month'))
                    ->formatStateUsing(fn (Due $record): string => Display::yearMonth($record->month)),
                TextColumn::make('type')->label(__('dues.type'))->badge(),
                TextColumn::make('amount_poisha')
                    ->label(__('dues.amount'))
                    ->formatStateUsing(fn (Due $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End),
                TextColumn::make('paid_poisha')
                    ->label(__('dues.paid'))
                    ->formatStateUsing(fn (Due $record): string => Display::money($record->paid_poisha))
                    ->alignment(Alignment::End)
                    ->visibleFrom('md'),
                TextColumn::make('outstanding_poisha')
                    ->label(__('dues.outstanding'))
                    ->formatStateUsing(fn (Due $record): string => Display::money($record->outstanding_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold'),
                TextColumn::make('due_date')
                    ->label(__('dues.due_date'))
                    ->formatStateUsing(fn (Due $record): string => Display::date($record->due_date))
                    ->visibleFrom('lg'),
                TextColumn::make('ratePlan.code')->label(__('dues.plan'))->visibleFrom('xl'),
                TextColumn::make('status')->badge(),
            ]);
    }
}
