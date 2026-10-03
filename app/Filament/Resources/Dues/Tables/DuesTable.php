<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dues\Tables;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\DueLedger;
use App\Filament\Resources\Dues\Actions\DueActions;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DuesTable
{
    public static function configure(Table $table): Table
    {
        $money = fn (string $column): Sum => Sum::make()
            ->label(__('journal.line.total'))
            ->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)));

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('member'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('month')->orderBy('member_id')->orderBy('id'))
            ->columns([
                TextColumn::make('member.member_no')
                    ->label(__('dues.member'))
                    ->formatStateUsing(fn (Due $record): string => $record->member->displayName())
                    ->url(fn (Due $record): string => MemberResource::getUrl('view', ['record' => $record->member_id]))
                    ->searchable(['member_no', 'name_bn', 'name_en', 'mobile']),
                TextColumn::make('month')
                    ->label(__('dues.month'))
                    ->formatStateUsing(fn (Due $record): string => Display::yearMonth($record->month))
                    ->sortable(),
                TextColumn::make('type')->label(__('dues.type'))->badge(),
                TextColumn::make('amount_poisha')
                    ->label(__('dues.amount'))
                    ->formatStateUsing(fn (Due $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End)
                    ->summarize($money('amount_poisha')),
                TextColumn::make('paid_poisha')
                    ->label(__('dues.paid'))
                    ->formatStateUsing(fn (Due $record): string => Display::money($record->paid_poisha))
                    ->alignment(Alignment::End)
                    ->visibleFrom('md'),
                TextColumn::make('outstanding_poisha')
                    ->label(__('dues.outstanding'))
                    ->formatStateUsing(fn (Due $record): string => Display::money($record->outstanding_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold')
                    ->summarize($money('outstanding_poisha')),
                TextColumn::make('due_date')
                    ->label(__('dues.due_date'))
                    ->formatStateUsing(fn (Due $record): string => Display::date($record->due_date))
                    ->visibleFrom('lg'),
                TextColumn::make('status')->label(__('dues.status_label'))->badge(),
            ])
            ->filters([
                SelectFilter::make('month')
                    ->label(__('dues.month'))
                    ->options(fn (): array => collect(app(DueLedger::class)->months())
                        ->mapWithKeys(fn (YearMonth $month): array => [$month->toDateString() => Display::yearMonth($month)])
                        ->all()),
                SelectFilter::make('type')->label(__('dues.type'))->options(DueType::class),
                SelectFilter::make('status')
                    ->label(__('dues.status_label'))
                    ->options(DueStatus::class)
                    ->default(DueStatus::Open->value),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                DueActions::waive()->iconButton(),
            ])
            ->emptyStateHeading(__('dues.empty_heading'))
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays);
    }
}
