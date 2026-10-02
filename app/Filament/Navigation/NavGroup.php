<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Sidebar groups in display order (SOMITI_SPEC.md §8.2).
 */
enum NavGroup: string implements HasIcon, HasLabel
{
    case Members = 'members';
    case Collections = 'collections';
    case Dues = 'dues';
    case Accounting = 'accounting';
    case Investments = 'investments';
    case YearEnd = 'year_end';
    case Exits = 'exits';
    case Governance = 'governance';
    case Reports = 'reports';
    case Settings = 'settings';
    case Audit = 'audit';

    /**
     * Groups for the panel, keyed by case name so resources can reference the enum directly.
     * Labels are closures so they follow the locale chosen in each request.
     *
     * @return array<string, NavigationGroup>
     */
    public static function navigationGroups(): array
    {
        $groups = [];

        foreach (self::cases() as $case) {
            $groups[$case->name] = NavigationGroup::make()
                ->label(fn (): string => $case->getLabel())
                ->icon($case->getIcon());
        }

        return $groups;
    }

    public function getLabel(): string
    {
        return __('nav.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Members => Heroicon::OutlinedUserGroup,
            self::Collections => Heroicon::OutlinedBanknotes,
            self::Dues => Heroicon::OutlinedCalendarDays,
            self::Accounting => Heroicon::OutlinedCalculator,
            self::Investments => Heroicon::OutlinedChartBar,
            self::YearEnd => Heroicon::OutlinedFlag,
            self::Exits => Heroicon::OutlinedArrowRightStartOnRectangle,
            self::Governance => Heroicon::OutlinedBuildingOffice2,
            self::Reports => Heroicon::OutlinedDocumentChartBar,
            self::Settings => Heroicon::OutlinedCog6Tooth,
            self::Audit => Heroicon::OutlinedShieldCheck,
        };
    }
}
