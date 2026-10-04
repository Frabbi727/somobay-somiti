<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Portal\MemberSummary;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Member\Widgets\MemberStatsWidget;
use App\Filament\Member\Widgets\RecentPaymentsWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

final class Dashboard extends BaseDashboard
{
    use ScopedToMember;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.dashboard');
    }

    public function getTitle(): string
    {
        $member = self::member();

        return __('portal.dashboard.welcome', ['name' => app()->getLocale() === 'bn' ? $member->name_bn : $member->name_en]);
    }

    public function getWidgets(): array
    {
        return [MemberStatsWidget::class, RecentPaymentsWidget::class];
    }

    public function getColumns(): int
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('payNow')
                ->label(__('portal.dashboard.pay_now'))
                ->tooltip(__('portal.dashboard.pay_now'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->url(PayOnline::getUrl())
                ->visible(fn (): bool => app(MemberSummary::class)->for(self::member())['outstanding']->isPositive()),
        ];
    }
}
