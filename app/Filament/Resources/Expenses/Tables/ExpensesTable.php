<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Tables;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Filament\Resources\Expenses\Actions\ExpenseActions;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
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

final class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['account', 'recorder', 'journalEntry']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('spent_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('spent_on')
                    ->label(__('expenses.field.spent_on'))
                    ->formatStateUsing(fn (Expense $record): string => Display::date($record->spent_on))
                    ->sortable(),
                TextColumn::make('expense_no')->label(__('expenses.field.number'))->searchable()->visibleFrom('md'),
                TextColumn::make('account.name_en')
                    ->label(__('expenses.field.category'))
                    ->formatStateUsing(fn (Expense $record): string => $record->account->displayName()),
                TextColumn::make('description')->label(__('expenses.field.description'))->limit(40)->searchable(['description', 'payee', 'reference']),
                TextColumn::make('paid_from')->label(__('expenses.field.paid_from'))->badge()->color('gray')->visibleFrom('lg'),
                TextColumn::make('amount_poisha')
                    ->label(__('expenses.field.amount'))
                    ->formatStateUsing(fn (Expense $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold')
                    ->sortable()
                    ->summarize(Sum::make()->label(__('journal.line.total'))->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))),
                TextColumn::make('status')->label(__('expenses.field.status'))->badge(),
                TextColumn::make('journalEntry.voucher_no')
                    ->label(__('expenses.field.voucher'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->placeholder('—')
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('expenses.field.status'))->options(ExpenseStatus::class),
                SelectFilter::make('account_id')->label(__('expenses.field.category'))->options(fn (): array => ExpenseForm::categories()),
                SelectFilter::make('paid_from')->label(__('expenses.field.paid_from'))->options(PaymentMethod::class),
                Filter::make('spent_between')
                    ->schema([
                        DatePicker::make('from')->label(__('expenses.field.from'))->native(false),
                        DatePicker::make('until')->label(__('expenses.field.until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('spent_on', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->whereDate('spent_on', '<=', $until))),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                ExpenseActions::approve()->iconButton(),
                ActionGroup::make([
                    ExpenseActions::reject(),
                    ExpenseActions::cancel(),
                    ExpenseActions::reverse(),
                ])->iconButton()->icon(Heroicon::OutlinedEllipsisVertical)->tooltip(__('common.more'))->color('gray'),
            ])
            ->emptyStateHeading(__('expenses.plural'))
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent);
    }
}
