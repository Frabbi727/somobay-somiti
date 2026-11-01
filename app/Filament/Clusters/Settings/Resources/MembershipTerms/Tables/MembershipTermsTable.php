<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Tables;

use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Actions\MembershipTermsActions;
use App\Filament\Support\Display;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class MembershipTermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('publisher')->withCount('acceptances'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('version desc nulls first')->orderByDesc('id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('version')
                    ->label(__('terms.admin.version'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Display::digits($state))
                    ->weight('bold'),
                TextColumn::make('status')->label(__('terms.admin.status'))->badge(),
                TextColumn::make('published_at')
                    ->label(__('terms.admin.published_at'))
                    ->formatStateUsing(fn (MembershipTerms $record): string => $record->published_at === null ? '—' : Display::dateTime($record->published_at)),
                TextColumn::make('publisher.name')
                    ->label(__('terms.admin.published_by'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('acceptances_count')
                    ->label(__('terms.admin.acceptances'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state))
                    ->alignment(Alignment::End),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip(__('common.view'))->icon(Heroicon::OutlinedEye)->color('gray'),
                EditAction::make()->iconButton()->tooltip(__('common.edit'))->icon(Heroicon::OutlinedPencilSquare)->color('warning'),
                MembershipTermsActions::publish()->iconButton(),
            ]);
    }
}
