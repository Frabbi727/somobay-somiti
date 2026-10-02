<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears;

use App\Domain\Accounting\Models\FiscalYear;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Filament\Resources\FiscalYears\Pages\ViewFiscalYear;
use App\Filament\Resources\FiscalYears\RelationManagers\PeriodsRelationManager;
use App\Filament\Resources\FiscalYears\Schemas\FiscalYearInfolist;
use App\Filament\Resources\FiscalYears\Tables\FiscalYearsTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

final class FiscalYearResource extends Resource
{
    protected static ?string $model = FiscalYear::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Accounting;

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return __('accounting.fiscal_year.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.fiscal_year.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return FiscalYearInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalYearsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PeriodsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalYears::route('/'),
            'view' => ViewFiscalYear::route('/{record}'),
        ];
    }
}
