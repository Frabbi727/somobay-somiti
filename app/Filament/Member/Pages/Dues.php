<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The member's dues, newest first; unpaid ones by default.
 */
final class Dues extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopedToMember;

    protected string $view = 'filament.member.table';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.dues');
    }

    public function getTitle(): string
    {
        return __('portal.nav.dues');
    }

    public function table(Table $table): Table
    {
        $money = fn (string $column, string $label): TextColumn => TextColumn::make($column)
            ->label($label)
            ->formatStateUsing(fn (Due $record): string => Display::money($record->{$column}))
            ->alignment(Alignment::End)
            ->summarize(Sum::make()->label(__('journal.line.total'))->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state))));

        return $table
            ->query(fn (): Builder => Due::query()->where('member_id', self::member()->id))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('month')->orderBy('id'))
            ->columns([
                TextColumn::make('month')->label(__('dues.month'))->formatStateUsing(fn (Due $record): string => Display::yearMonth($record->month))->sortable(),
                TextColumn::make('type')->label(__('dues.type'))->badge()->color('gray'),
                $money('amount_poisha', __('dues.amount')),
                $money('paid_poisha', __('dues.paid')),
                $money('outstanding_poisha', __('dues.outstanding'))->weight('bold'),
                TextColumn::make('due_date')->label(__('dues.due_date'))->formatStateUsing(fn (Due $record): string => Display::date($record->due_date))->visibleFrom('md'),
                TextColumn::make('status')->label(__('dues.status_label'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('dues.status_label'))->options(DueStatus::class)->default(DueStatus::Open->value),
                SelectFilter::make('type')->label(__('dues.type'))->options(DueType::class),
            ])
            ->emptyStateHeading(__('portal.dues.none'))
            ->emptyStateIcon(Heroicon::OutlinedCheckBadge);
    }
}
