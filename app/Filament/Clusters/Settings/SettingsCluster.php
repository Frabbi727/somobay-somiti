<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings;

use App\Enums\Area;
use App\Filament\Clusters\Settings\Pages\BackupsPage;
use App\Filament\Clusters\Settings\Pages\RolePermissionsPage;
use App\Filament\Navigation\NavGroup;
use App\Models\User;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings (SOMITI_SPEC.md §8.2): rates & plans, late fee rules, payment methods, users, SMS templates.
 */
final class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'settings';

    /** Its pages as tabs across the top, so each page keeps the full width. */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return __('rates.settings');
    }

    /**
     * Only shown to someone who can open something inside it.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && (Area::RatePlans->allows($user) || Area::Messaging->allows($user) || $user->can('viewAny', User::class) || RolePermissionsPage::canAccess() || BackupsPage::canAccess());
    }

    public static function getClusterBreadcrumb(): string
    {
        return __('rates.settings');
    }
}
