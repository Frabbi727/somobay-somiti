<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Filament\Resources\JournalEntries\Actions\ReverseJournalAction;
use App\Filament\Support\Display;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('lines', 'debit_poisha')
                ->withExists('reversal')
                ->with('postedBy'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('entry_date')->orderByDesc('id'))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->searchDebounce('400ms')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->columns([
                TextColumn::make('voucher_no')
                    ->label(__('journal.voucher.voucher_no'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->weight('bold')
                    ->copyable()
                    ->copyableState(fn (JournalEntry $record): string => $record->voucher_no)
                    ->searchable(),
                TextColumn::make('voucher_type')
                    ->label(__('journal.voucher.type'))
                    ->badge(),
                TextColumn::make('entry_date')
                    ->label(__('journal.voucher.entry_date'))
                    ->formatStateUsing(fn (JournalEntry $record): string => Display::date($record->entry_date))
                    ->sortable(),
                TextColumn::make('narration')
                    ->label(__('journal.voucher.narration'))
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('lines_sum_debit_poisha')
                    ->label(__('journal.voucher.amount'))
                    ->formatStateUsing(fn (JournalEntry $record): string => Display::money($record->amount()))
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('state')
                    ->label(__('journal.voucher.state'))
                    ->badge()
                    ->state(fn (JournalEntry $record): string => match (true) {
                        $record->isReversal() => __('journal.voucher.state_reversal'),
                        $record->isReversed() => __('journal.voucher.state_reversed'),
                        default => __('journal.voucher.state_posted'),
                    })
                    ->color(fn (JournalEntry $record): string => match (true) {
                        $record->isReversal() => 'warning',
                        $record->isReversed() => 'danger',
                        default => 'success',
                    }),
                TextColumn::make('postedBy.name')
                    ->label(__('journal.voucher.posted_by'))
                    ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('voucher_type')
                    ->label(__('journal.voucher.type'))
                    ->options(VoucherType::class),
                SelectFilter::make('fiscal_year_id')
                    ->label(__('journal.voucher.fiscal_year'))
                    ->relationship('fiscalYear', 'code'),
                Filter::make('entry_date')
                    ->schema([
                        DatePicker::make('from')->label(__('journal.voucher.from'))->native(false),
                        DatePicker::make('until')->label(__('journal.voucher.until'))->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '<=', $date))),
                TernaryFilter::make('reversed')
                    ->label(__('journal.voucher.state_reversed'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('reversal'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('reversal'),
                    ),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                ViewAction::make()
                    ->iconButton()
                    ->tooltip(__('common.view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray'),
                ReverseJournalAction::make()->iconButton(),
            ])
            ->emptyStateHeading(__('journal.voucher.empty_heading'))
            ->emptyStateDescription(__('journal.voucher.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentText);
    }
}
