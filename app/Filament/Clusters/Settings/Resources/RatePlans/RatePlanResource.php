<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans;

use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\CreateRatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\EditRatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\ListRatePlans;
use App\Filament\Clusters\Settings\Resources\RatePlans\Pages\ViewRatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Schemas\RatePlanForm;
use App\Filament\Clusters\Settings\Resources\RatePlans\Schemas\RatePlanInfolist;
use App\Filament\Clusters\Settings\Resources\RatePlans\Tables\RatePlansTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class RatePlanResource extends Resource
{
    protected static ?string $model = RatePlan::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'rate-plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return __('rates.plan.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rates.plan.plural');
    }

    /**
     * Number of plans waiting for the committee, shown on the menu.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = RatePlan::query()->where('status', RatePlanStatus::PendingApproval)->count();

        return $pending === 0 ? null : Display::digits($pending);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return RatePlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RatePlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RatePlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRatePlans::route('/'),
            'create' => CreateRatePlan::route('/create'),
            'view' => ViewRatePlan::route('/{record}'),
            'edit' => EditRatePlan::route('/{record}/edit'),
        ];
    }
}
