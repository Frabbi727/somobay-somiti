<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Tables;

use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Filament\Resources\FundTransfers\Actions\FundTransferActions;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FundTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['journalEntry']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('transferred_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('transferred_on')
                    ->label(__('transfers.field.transferred_on'))
                    ->formatStateUsing(fn (FundTransfer $record): string => Display::date($record->transferred_on))
                    ->sortable(),
                TextColumn::make('transfer_no')->label(__('transfers.field.number'))->searchable(['transfer_no', 'reference'])->visibleFrom('md'),
                TextColumn::make('from_method')->label(__('transfers.field.from'))->badge()->color('gray'),
                TextColumn::make('to_method')->label(__('transfers.field.to'))->badge()->color('gray'),
                TextColumn::make('amount_poisha')
                    ->label(__('transfers.field.amount'))
                    ->formatStateUsing(fn (FundTransfer $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold')
                    ->sortable()
                    ->summarize(Sum::make()->label(__('journal.line.total'))->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))),
                TextColumn::make('charge_poisha')
                    ->label(__('transfers.field.charge'))
                    ->formatStateUsing(fn (FundTransfer $record): string => Display::money($record->charge_poisha))
                    ->alignment(Alignment::End)
                    ->visibleFrom('lg'),
                TextColumn::make('status')->label(__('transfers.field.status'))->badge(),
                TextColumn::make('journalEntry.voucher_no')
                    ->label(__('transfers.field.voucher'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->placeholder('—')
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('transfers.field.status'))->options(TransferStatus::class),
                Filter::make('transferred_between')
                    ->schema([
                        DatePicker::make('from')->label(__('transfers.field.date_from'))->native(false),
                        DatePicker::make('until')->label(__('transfers.field.date_until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('transferred_on', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->whereDate('transferred_on', '<=', $until))),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                FundTransferActions::approve()->iconButton(),
                ActionGroup::make([
                    FundTransferActions::reject(),
                    FundTransferActions::cancel(),
                    FundTransferActions::reverse(),
                ])->iconButton()->icon(Heroicon::OutlinedEllipsisVertical)->tooltip(__('common.more'))->color('gray'),
            ])
            ->emptyStateHeading(__('transfers.plural'))
            ->emptyStateIcon(Heroicon::OutlinedArrowsRightLeft);
    }
}
