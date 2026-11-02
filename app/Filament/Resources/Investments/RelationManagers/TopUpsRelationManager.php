<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\RelationManagers;

use App\Domain\Investments\Models\InvestmentTopUp;
use App\Filament\Resources\Investments\Actions\InvestmentTopUpActions;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Money added to the investment after the first amount, each waiting for or past its approval.
 */
final class TopUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'topUps';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('investments.top_up.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['investment', 'recorder', 'approver', 'journalEntry']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('invested_on')->orderBy('id'))
            ->paginated(false)
            ->emptyStateHeading(__('investments.top_up.empty'))
            ->columns([
                TextColumn::make('invested_on')
                    ->label(__('investments.field.date'))
                    ->formatStateUsing(fn (InvestmentTopUp $record): string => Display::date($record->invested_on)),
                TextColumn::make('amount_poisha')
                    ->label(__('investments.field.amount'))
                    ->formatStateUsing(fn (InvestmentTopUp $record): string => Display::money($record->amount_poisha))
                    ->alignment(Alignment::End)
                    ->weight('bold'),
                TextColumn::make('funded_from')->label(__('investments.field.funded_from'))->badge()->color('gray'),
                TextColumn::make('status')->label(__('investments.field.status'))->badge(),
                TextColumn::make('journalEntry.voucher_no')
                    ->label(__('investments.field.voucher'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->placeholder('—'),
                TextColumn::make('recorder.name')->label(__('investments.field.recorded_by'))->visibleFrom('md'),
                TextColumn::make('approver.name')->label(__('investments.field.approved_by'))->placeholder('—')->visibleFrom('md'),
                TextColumn::make('notes')
                    ->label(__('investments.field.notes'))
                    ->state(fn (InvestmentTopUp $record): ?string => $record->rejection_reason ?? $record->notes)
                    ->placeholder('—')
                    ->wrap()
                    ->visibleFrom('lg'),
            ])
            ->recordActions([
                InvestmentTopUpActions::approve(),
                InvestmentTopUpActions::reject(),
                InvestmentTopUpActions::cancel(),
            ]);
    }
}
