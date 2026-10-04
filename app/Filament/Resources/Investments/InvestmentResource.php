<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments;

use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Investments\Pages\CreateInvestment;
use App\Filament\Resources\Investments\Pages\ListInvestments;
use App\Filament\Resources\Investments\Pages\ViewInvestment;
use App\Filament\Resources\Investments\Schemas\InvestmentForm;
use App\Filament\Resources\Investments\Schemas\InvestmentInfolist;
use App\Filament\Resources\Investments\Tables\InvestmentsTable;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class InvestmentResource extends Resource
{
    protected static ?string $model = Investment::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Investments;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    public static function getModelLabel(): string
    {
        return __('investments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('investments.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Investment::query()->where('status', InvestmentStatus::Pending)->count();

        return $pending === 0 ? null : Display::digits($pending);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return InvestmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvestmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvestmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestments::route('/'),
            'create' => CreateInvestment::route('/create'),
            'view' => ViewInvestment::route('/{record}'),
        ];
    }
}
