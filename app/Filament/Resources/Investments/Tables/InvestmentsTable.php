<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Tables;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Investments\Models\Investment;
use App\Filament\Resources\Investments\Actions\InvestmentActions;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InvestmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum('ledger as book_value_poisha', 'delta_poisha'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('invested_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('investment_no')->label(__('investments.field.number'))->searchable(['investment_no', 'institution', 'instrument_no']),
                TextColumn::make('type')->label(__('investments.field.type'))->badge()->color('gray'),
                TextColumn::make('institution')->label(__('investments.field.institution'))->limit(30),
                TextColumn::make('invested_on')
                    ->label(__('investments.field.invested_on'))
                    ->formatStateUsing(fn (Investment $record): string => Display::date($record->invested_on))
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('matures_on')
                    ->label(__('investments.field.matures_on'))
                    ->state(fn (Investment $record): ?string => $record->matures_on === null ? null : Display::date($record->matures_on))
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                TextColumn::make('principal_poisha')
                    ->label(__('investments.field.principal'))
                    ->formatStateUsing(fn (Investment $record): string => Display::money($record->principal_poisha))
                    ->alignment(Alignment::End),
                TextColumn::make('book_value_poisha')
                    ->label(__('investments.field.book_value'))
                    ->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))
                    ->alignment(Alignment::End)
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('journal.line.total'))->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))),
                TextColumn::make('status')->label(__('investments.field.status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('investments.field.status'))->options(InvestmentStatus::class),
                SelectFilter::make('type')->label(__('investments.field.type'))->options(InvestmentType::class),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                InvestmentActions::approve()->iconButton(),
                ActionGroup::make([
                    InvestmentActions::reject(),
                    InvestmentActions::cancel(),
                    InvestmentActions::income(),
                    InvestmentActions::close(),
                ])->iconButton()->icon(Heroicon::OutlinedEllipsisVertical)->tooltip(__('common.more'))->color('gray'),
            ])
            ->emptyStateHeading(__('investments.plural'))
            ->emptyStateIcon(Heroicon::OutlinedArrowTrendingUp);
    }
}
