<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Tables;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Filament\Resources\Accounts\Actions\AccountActions;
use App\Filament\Support\Display;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchDebounce('400ms')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->columns([
                TextColumn::make('code')
                    ->label(__('accounting.account.code'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->weight('bold')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('accounting.account.name'))
                    ->state(fn (Account $record): string => Display::isBangla() ? $record->name_bn : $record->name_en)
                    ->description(fn (Account $record): string => Display::isBangla() ? $record->name_en : $record->name_bn)
                    ->searchable(['name_en', 'name_bn']),
                TextColumn::make('type')
                    ->label(__('accounting.account.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('normal_balance')
                    ->label(__('accounting.account.normal_balance'))
                    ->badge()
                    ->visibleFrom('md')
                    ->toggleable(),
                IconColumn::make('is_control')
                    ->label(__('accounting.account.is_control'))
                    ->boolean()
                    ->visibleFrom('lg')
                    ->toggleable(),
                IconColumn::make('requires_member')
                    ->label(__('accounting.account.requires_member'))
                    ->boolean()
                    ->visibleFrom('lg')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(__('accounting.account.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('accounting.account.type'))
                    ->options(AccountType::class),
                TernaryFilter::make('is_active')
                    ->label(__('accounting.account.is_active')),
                TrashedFilter::make(),
            ], layout: FiltersLayout::AboveContentCollapsible)
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
                    ->color('warning')
                    ->hidden(fn (Account $record): bool => $record->trashed()),
                ActionGroup::make([
                    AccountActions::toggleActive(),
                    AccountActions::delete(),
                    AccountActions::restore(),
                ])
                    ->iconButton()
                    ->tooltip(__('common.more')),
            ])
            ->emptyStateHeading(__('accounting.account.empty_heading'))
            ->emptyStateDescription(__('accounting.account.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack);
    }
}
