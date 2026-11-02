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
            Stat::make(__('portal.dashboard.due_this_month'), Display::money($summary['due_this_month']))
                ->icon(Heroicon::OutlinedCalendar)
                ->color($summary['due_this_month']->isPositive() ? 'warning' : 'success'),
            Stat::make(__('portal.dashboard.due_earlier'), Display::money($summary['due_earlier']))
                ->icon(Heroicon::OutlinedClock)
                ->color($summary['due_earlier']->isPositive() ? 'danger' : 'success'),
            Stat::make(__('portal.dashboard.billed'), Display::money($summary['billed']))
                ->icon(Heroicon::OutlinedDocumentText)
                ->description(__('portal.dashboard.paid_and_waived', [
                    'paid' => Display::money($summary['paid']),
                    'waived' => Display::money($summary['waived']),
                ])),
            Stat::make(__('portal.dashboard.monthly_deposit'), $summary['monthly_deposit'] === null ? '—' : Display::money($summary['monthly_deposit']))
                ->icon(Heroicon::OutlinedBanknotes)
                ->description(__('portal.dashboard.monthly_deposit_help', ['count' => Display::digits($summary['shares'])])),
        ];
    }
}
