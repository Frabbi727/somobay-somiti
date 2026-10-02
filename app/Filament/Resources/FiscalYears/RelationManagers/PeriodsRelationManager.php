<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\RelationManagers;

use App\Domain\Accounting\Models\Period;
use App\Filament\Resources\FiscalYears\Actions\FiscalYearActions;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'periods';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('accounting.fiscal_year.periods');
    }

    /**
     * Lock/unlock are the only actions here and are guarded by policies, so they stay
     * available on the read-only view page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Period $record): string => Display::yearMonth($record->month))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['lockedBy', 'fiscalYear']))
            ->defaultSort('sequence')
            ->paginated(false)
            ->columns([
                TextColumn::make('sequence')
                    ->label(__('accounting.period.sequence'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state)),
                TextColumn::make('month')
                    ->label(__('accounting.period.month'))
                    ->state(fn (Period $record): string => Display::yearMonth($record->month))
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label(__('accounting.period.status'))
                    ->badge(),
                TextColumn::make('locked_at')
                    ->label(__('accounting.period.locked_at'))
                    ->state(fn (Period $record): string => Display::dateTime($record->locked_at))
                    ->visibleFrom('md'),
                TextColumn::make('lockedBy.name')
                    ->label(__('accounting.period.locked_by'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                FiscalYearActions::lockPeriod()->iconButton(),
                FiscalYearActions::unlockPeriod()->iconButton(),
            ]);
    }
}
