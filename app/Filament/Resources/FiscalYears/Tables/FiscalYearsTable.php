<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Tables;

use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Filament\Resources\FiscalYears\Actions\FiscalYearActions;
use App\Filament\Support\Display;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FiscalYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'periods as locked_periods_count' => fn (Builder $periods): Builder => $periods->where('status', PeriodStatus::Locked),
            ]))
            ->defaultSort('start_year', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('code')
                    ->label(__('accounting.fiscal_year.code'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('starts_on')
                    ->label(__('accounting.fiscal_year.starts_on'))
                    ->state(fn (FiscalYear $record): string => Display::date($record->starts_on))
                    ->visibleFrom('md'),
                TextColumn::make('ends_on')
                    ->label(__('accounting.fiscal_year.ends_on'))
                    ->state(fn (FiscalYear $record): string => Display::date($record->ends_on))
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('accounting.fiscal_year.status'))
                    ->badge(),
                TextColumn::make('locked_periods_count')
                    ->label(__('accounting.fiscal_year.locked_periods'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state.'/12')),
                TextColumn::make('closed_at')
                    ->label(__('accounting.fiscal_year.closed_at'))
                    ->state(fn (FiscalYear $record): string => Display::dateTime($record->closed_at))
                    ->visibleFrom('lg')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('accounting.fiscal_year.status'))
                    ->options(FiscalYearStatus::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->tooltip(__('common.view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray'),
                FiscalYearActions::close()->iconButton(),
            ])
            ->emptyStateHeading(__('accounting.fiscal_year.empty_heading'))
            ->emptyStateDescription(__('accounting.fiscal_year.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateActions([
                FiscalYearActions::openNext(),
            ]);
    }
}
