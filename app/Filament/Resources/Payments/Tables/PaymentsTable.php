<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Tables;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Filament\Pages\Collections\CollectPayment;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'recorder', 'journalEntry']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('received_on')->orderByDesc('id'))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchDebounce('400ms')
            ->persistFiltersInSession()
            ->columns([
                TextColumn::make('received_on')
                    ->label(__('payments.field.received_on'))
                    ->formatStateUsing(fn (Payment $record): string => Display::date($record->received_on))
                    ->sortable(),
                TextColumn::make('member.member_no')
                    ->label(__('payments.field.member'))
                    ->formatStateUsing(fn (Payment $record): string => $record->member->displayName())
                    ->searchable(['member_no', 'name_bn', 'name_en', 'mobile']),
                TextColumn::make('method')->label(__('payments.field.method'))->badge()->color('gray'),
                TextColumn::make('amount_poisha')
                    ->label(__('payments.field.amount'))
                    ->formatStateUsing(fn (Payment $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('journal.line.total'))->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))),
                TextColumn::make('trx_id')->label(__('payments.field.trx_id'))->searchable()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('status')->label(__('payments.field.status'))->badge(),
                TextColumn::make('journalEntry.voucher_no')
                    ->label(__('payments.field.voucher'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                TextColumn::make('recorder.name')->label(__('payments.field.recorded_by'))->visibleFrom('xl')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payments.field.status'))
                    ->options(PaymentStatus::class)
                    ->default(PaymentStatus::Pending->value),
                SelectFilter::make('method')->label(__('payments.field.method'))->options(PaymentMethod::class),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip(__('common.view'))->icon(Heroicon::OutlinedEye)->color('gray'),
                PaymentActions::approve()->iconButton(),
                PaymentActions::receipt()->iconButton(),
                ActionGroup::make([
                    PaymentActions::reject(),
                    PaymentActions::cancel(),
                    PaymentActions::reverse(),
                ])->iconButton()->tooltip(__('common.more')),
            ])
            ->toolbarActions([
                PaymentActions::bulkApprove(),
            ])
            ->checkIfRecordIsSelectableUsing(fn (Payment $record): bool => $record->status === PaymentStatus::Pending)
            ->emptyStateHeading(__('payments.plural'))
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateActions([
                Action::make('collect')
                    ->label(__('payments.collect.title'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => CollectPayment::getUrl())
                    ->visible(fn (): bool => CollectPayment::canAccess()),
            ]);
    }
}
