<?php

declare(strict_types=1);

namespace App\Filament\Resources\YearEnds;

use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\YearEnds\Pages\ListYearEnds;
use App\Filament\Resources\YearEnds\Pages\ViewYearEnd;
use App\Filament\Resources\YearEnds\RelationManagers\DividendLinesRelationManager;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class YearEndResource extends Resource
{
    protected static ?string $model = YearEnd::class;

    protected static ?string $slug = 'year-ends';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::YearEnd;

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    public static function getModelLabel(): string
    {
        return __('year_end.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('year_end.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (string $field, string $label): TextEntry => TextEntry::make($field)
            ->label($label)
            ->formatStateUsing(fn (YearEnd $record): string => Display::money($record->{$field}));
        $fund = fn (string $key): TextEntry => TextEntry::make('appropriation.'.$key)
            ->label(__('year_end.fund.'.$key))
            ->state(fn (YearEnd $record): string => Display::money(Money::ofPoisha((int) ($record->appropriation[$key] ?? 0))));

        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('fiscalYear.code')->label(__('year_end.wizard.fiscal_year'))->weight('bold'),
                    TextEntry::make('status')->label(__('year_end.field.status'))->badge(),
                    $money('net_profit_poisha', __('year_end.field.net_profit')),
                    $money('prior_loss_poisha', __('year_end.field.prior_loss')),
                    $money('loss_offset_poisha', __('year_end.field.loss_offset')),
                    $fund('reserve'),
                    $fund('development_fund'),
                    $fund('bad_debt_fund'),
                    $fund('other_funds'),
                    $money('dividend_pool_poisha', __('year_end.field.dividend_pool')),
                    TextEntry::make('total_share_months')->label(__('year_end.field.share_months'))->formatStateUsing(fn (int $state): string => Display::digits($state)),
                    TextEntry::make('resolution_id')
                        ->label(__('year_end.wizard.resolution'))
                        ->state(fn (YearEnd $record): ?string => $record->resolution?->displayName())
                        ->placeholder('—'),
                ]),
            Section::make(__('year_end.field.approvals'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('preparer.name')->label(__('year_end.field.prepared_by')),
                    TextEntry::make('president_approved_at')
                        ->label(__('year_end.field.president_approval'))
                        ->state(fn (YearEnd $record): ?string => $record->president_approved_at === null ? null : Display::dateTime($record->president_approved_at))
                        ->placeholder(__('year_end.field.awaiting')),
                    TextEntry::make('accountant_approved_at')
                        ->label(__('year_end.field.accountant_approval'))
                        ->state(fn (YearEnd $record): ?string => $record->accountant_approved_at === null ? null : Display::dateTime($record->accountant_approved_at))
                        ->placeholder(__('year_end.field.awaiting')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('fiscalYear'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('fiscalYear.code')->label(__('year_end.wizard.fiscal_year'))->weight('bold'),
                TextColumn::make('status')->label(__('year_end.field.status'))->badge(),
                TextColumn::make('net_profit_poisha')
                    ->label(__('year_end.field.net_profit'))
                    ->formatStateUsing(fn (YearEnd $record): string => Display::money($record->net_profit_poisha))
                    ->alignment(Alignment::End),
                TextColumn::make('dividend_pool_poisha')
                    ->label(__('year_end.field.dividend_pool'))
                    ->formatStateUsing(fn (YearEnd $record): string => Display::money($record->dividend_pool_poisha))
                    ->alignment(Alignment::End),
                TextColumn::make('posted_at')
                    ->label(__('year_end.field.posted_at'))
                    ->state(fn (YearEnd $record): ?string => $record->posted_at === null ? null : Display::dateTime($record->posted_at))
                    ->placeholder('—'),
            ])
            ->recordActions([ViewAction::make()->iconButton()])
            ->emptyStateHeading(__('year_end.plural'))
            ->emptyStateIcon(Heroicon::OutlinedFlag);
    }

    public static function getRelations(): array
    {
        return [DividendLinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListYearEnds::route('/'),
            'view' => ViewYearEnd::route('/{record}'),
        ];
    }
}
