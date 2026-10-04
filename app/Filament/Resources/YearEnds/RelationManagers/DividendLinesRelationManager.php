<?php

declare(strict_types=1);

namespace App\Filament\Resources\YearEnds\RelationManagers;

use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Resources\YearEnds\Actions\YearEndActions;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DividendLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'dividendLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('year_end.dividend_register');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('member'))
            ->defaultSort('member_id')
            ->columns([
                TextColumn::make('member.member_no')
                    ->label(__('payments.field.member'))
                    ->formatStateUsing(fn (DividendLine $record): string => $record->member->displayName())
                    ->searchable(['member_no', 'name_en', 'name_bn']),
                TextColumn::make('share_months')
                    ->label(__('year_end.field.share_months'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state))
                    ->alignment(Alignment::End),
                TextColumn::make('amount_poisha')
                    ->label(__('year_end.field.dividend'))
                    ->formatStateUsing(fn (DividendLine $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('journal.line.total'))->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))),
                TextColumn::make('status')->label(__('year_end.field.status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('year_end.field.status'))->options(DividendStatus::class),
            ])
            ->headerActions($this->getOwnerRecord() instanceof YearEnd ? [YearEndActions::creditAllToSavings()->record($this->getOwnerRecord())] : [])
            ->recordActions([YearEndActions::settleDividend()->iconButton()]);
    }
}
