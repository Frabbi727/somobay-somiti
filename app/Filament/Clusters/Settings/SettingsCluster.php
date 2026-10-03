<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings;

use App\Filament\Navigation\NavGroup;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings (SOMITI_SPEC.md §8.2): rates & plans, late fee rules, payment methods, users, SMS templates.
 */
final class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?string $slug = 'settings';

    public static function getNavigationLabel(): string
    {
        return __('rates.settings');
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('rates.settings');
    }
}
