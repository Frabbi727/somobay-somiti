<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Domain\Members\Portal\MemberSummary;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Member\Pages\Dues;
use App\Filament\Support\Display;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class MemberStatsWidget extends StatsOverviewWidget
{
    use ScopedToMember;

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $summary = app(MemberSummary::class)->for(self::member());

        $paidThrough = Stat::make(__('portal.dashboard.paid_through'), $summary['paid_through'] === null ? __('portal.dashboard.not_paid_yet') : Display::yearMonth($summary['paid_through']))
            ->icon(Heroicon::OutlinedCalendarDays);

        if ($summary['estimate'] > 0) {
            $paidThrough->description(__('portal.dashboard.estimate', ['count' => Display::digits($summary['estimate'])]));
        }

        return [
            Stat::make(__('portal.dashboard.savings'), Display::money($summary['savings']))
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->color('success'),
            $paidThrough,
            Stat::make(__('portal.dashboard.outstanding'), Display::money($summary['outstanding']))
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->color($summary['outstanding']->isPositive() ? 'danger' : 'success')
                ->url(Dues::getUrl()),
            Stat::make(__('portal.dashboard.advance'), Display::money($summary['advance']))
                ->icon(Heroicon::OutlinedForward)
                ->description(__('portal.dashboard.shares').': '.Display::digits($summary['shares'])),
        ];
    }
}
