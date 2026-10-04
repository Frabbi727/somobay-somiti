<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementImport;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\StatementImports\Pages\CreateStatementImport;
use App\Filament\Resources\StatementImports\Pages\ListStatementImports;
use App\Filament\Resources\StatementImports\Pages\ViewStatementImport;
use App\Filament\Resources\StatementImports\RelationManagers\LinesRelationManager;
use App\Filament\Resources\StatementImports\Schemas\StatementImportForm;
use App\Filament\Resources\StatementImports\Schemas\StatementImportInfolist;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class StatementImportResource extends Resource
{
    protected static ?string $model = StatementImport::class;

    protected static ?string $slug = 'statements';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Accounting;

    protected static ?int $navigationSort = 45;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    public static function getModelLabel(): string
    {
        return __('statements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('statements.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('statements.navigation');
    }

    public static function form(Schema $schema): Schema
    {
        return StatementImportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StatementImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('importer')
                ->withCount(['lines as unmatched_count' => fn (Builder $query): Builder => $query->where('status', StatementLineStatus::Unmatched)]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('method')->label(__('statements.field.account'))->badge()->color('gray'),
                TextColumn::make('filename')->label(__('statements.field.filename'))->searchable()->limit(40),
                TextColumn::make('period_from')
                    ->label(__('statements.field.period'))
                    ->state(fn (StatementImport $record): string => Display::date($record->period_from).' – '.Display::date($record->period_to)),
                TextColumn::make('lines_count')
                    ->label(__('statements.field.lines'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state)),
                TextColumn::make('unmatched_count')
                    ->label(__('statements.status.unmatched'))
                    ->formatStateUsing(fn (int $state): string => Display::digits($state))
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'warning'),
                TextColumn::make('importer.name')->label(__('statements.field.imported_by'))->visibleFrom('lg'),
                TextColumn::make('created_at')
                    ->label(__('statements.field.imported_at'))
                    ->state(fn (StatementImport $record): string => Display::dateTime($record->created_at))
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('method')->label(__('statements.field.account'))->options(StatementImportForm::accounts()),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
            ])
            ->emptyStateHeading(__('statements.plural'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentMagnifyingGlass);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatementImports::route('/'),
            'create' => CreateStatementImport::route('/import'),
            'view' => ViewStatementImport::route('/{record}'),
        ];
    }
}
