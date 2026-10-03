<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Tables;

use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Actions\RatePlanActions;
use App\Filament\Clusters\Settings\Resources\RatePlans\Support\RatePlanPresenter;
use App\Filament\Support\Display;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rate timeline: newest effective month first.
 */
final class RatePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('effective_from')->orderByDesc('id'))
            ->columns([
                TextColumn::make('effective_from')
                    ->label(__('rates.plan.effective_from'))
                    ->formatStateUsing(fn (RatePlan $record): string => Display::yearMonth($record->effective_from))
                    ->description(fn (RatePlan $record): string => $record->code)
                    ->weight('bold'),
                TextColumn::make('share_unit_poisha')
                    ->label(__('rates.plan.share_unit'))
                    ->formatStateUsing(fn (RatePlan $record): string => Display::money($record->share_unit_poisha))
                    ->alignment(Alignment::End),
                TextColumn::make('service_charge_per_share_poisha')
                    ->label(__('rates.plan.service_charge'))
                    ->formatStateUsing(fn (RatePlan $record): string => Display::money($record->service_charge_per_share_poisha))
                    ->alignment(Alignment::End)
                    ->visibleFrom('md'),
                TextColumn::make('registration_fee_per_share_poisha')
                    ->label(__('rates.plan.registration_fee'))
                    ->formatStateUsing(fn (RatePlan $record): string => Display::money($record->registration_fee_per_share_poisha))
                    ->alignment(Alignment::End)
                    ->visibleFrom('lg'),
                TextColumn::make('due_day')
                    ->label(__('rates.plan.due_day'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state))
                    ->visibleFrom('lg'),
                TextColumn::make('late_fee')
                    ->label(__('rates.plan.late_fee'))
                    ->state(fn (RatePlan $record): string => RatePlanPresenter::lateFee(RatePlanData::fromPlan($record)))
                    ->wrap()
                    ->visibleFrom('xl')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('rates.plan.status'))
                    ->badge()
                    ->description(fn (RatePlan $record): string => RatePlanPresenter::pendingRoles($record)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('rates.plan.status'))
                    ->options(RatePlanStatus::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->tooltip(__('common.view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray'),
                EditAction::make()
                    ->iconButton()
                    ->tooltip(__('common.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('warning'),
                RatePlanActions::approve()->iconButton(),
                ActionGroup::make([
                    RatePlanActions::submit(),
                    RatePlanActions::reject(),
                    RatePlanActions::duplicate(),
                    RatePlanActions::cancel(),
                    RatePlanActions::delete(),
                ])
                    ->iconButton()
                    ->tooltip(__('common.more')),
            ])
            ->emptyStateHeading(__('rates.plan.empty_heading'))
            ->emptyStateDescription(__('rates.plan.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }
}
