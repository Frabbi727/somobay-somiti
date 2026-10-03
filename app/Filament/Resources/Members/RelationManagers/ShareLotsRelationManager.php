<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\RelationManagers;

use App\Domain\Members\Models\ShareLot;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ShareLotsRelationManager extends RelationManager
{
    protected static string $relationship = 'shareLots';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('members.share.lots');
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('shares')
                    ->label(__('members.share.lot_shares'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state))
                    ->description(fn (ShareLot $record): string => $record->continues_lot_id === null ? '' : (string) __('members.share.continuation')),
                TextColumn::make('effective_from')
                    ->label(__('members.share.from'))
                    ->formatStateUsing(fn (ShareLot $record): string => Display::yearMonth($record->effective_from)),
                TextColumn::make('ended_from')
                    ->label(__('members.share.until'))
                    ->formatStateUsing(fn (ShareLot $record): string => $record->ended_from === null ? '—' : Display::yearMonth($record->ended_from->previous()))
                    ->placeholder('—'),
            ]);
    }
}
