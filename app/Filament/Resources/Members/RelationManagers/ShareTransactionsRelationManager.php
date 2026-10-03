<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\RelationManagers;

use App\Domain\Members\Models\ShareTransaction;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ShareTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'shareTransactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('members.share.history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('creator'))
            ->paginated(false)
            ->columns([
                TextColumn::make('effective_from')
                    ->label(__('members.share.from'))
                    ->formatStateUsing(fn (ShareTransaction $record): string => Display::yearMonth($record->effective_from)),
                TextColumn::make('type')->label(__('members.share.change'))->badge(),
                TextColumn::make('shares')
                    ->label(__('members.share.lot_shares'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state)),
                TextColumn::make('shares_after')
                    ->label(__('members.share.after'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state))
                    ->weight('bold'),
                TextColumn::make('reason')->label(__('members.share.reason'))->placeholder('—')->wrap(),
                TextColumn::make('creator.name')->label(__('members.share.by'))->visibleFrom('lg'),
            ]);
    }
}
