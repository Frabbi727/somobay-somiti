<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Accounting\AccountCode;
use App\Domain\Reporting\SomitiSnapshot;
use App\Enums\Area;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Support\Display;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The somiti at a glance. Money figures only for roles that work with money (Area::Collections).
 */
final class SomitiOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected function getHeading(): string
    {
        return __('dashboard.overview');
    }

    public static function canView(): bool
    {
        return auth()->user() instanceof User && Area::Members->allows(auth()->user());
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $snapshot = app(SomitiSnapshot::class);
        $members = $snapshot->activeMembers();
        $overdue = $snapshot->membersOverdue(CarbonImmutable::now(YearMonth::TIMEZONE));

        $stats = [
            Stat::make(__('dashboard.active_members'), Display::digits($members))->icon(Heroicon::OutlinedUsers)->url(MemberResource::getUrl()),
            Stat::make(__('dashboard.members_overdue'), Display::digits($overdue))->icon(Heroicon::OutlinedExclamationTriangle)->color($overdue > 0 ? 'danger' : 'success'),
        ];

        if (! $user instanceof User || ! Area::Collections->allows($user)) {
            return $stats;
        }

        return [
            ...$stats,
            Stat::make(__('dashboard.collected_this_month'), Display::money($snapshot->collectedIn(YearMonth::current())))->icon(Heroicon::OutlinedBanknotes)->color('success'),
            Stat::make(__('dashboard.member_savings'), Display::money($snapshot->balance(AccountCode::MEMBER_SAVINGS, credit: true)))->icon(Heroicon::OutlinedBuildingLibrary),
            Stat::make(__('dashboard.cash_in_hand'), Display::money($snapshot->balance(AccountCode::CASH)))->icon(Heroicon::OutlinedWallet),
            Stat::make(__('dashboard.bank_and_wallets'), Display::money(
                $snapshot->balance(AccountCode::BANK)->plus($snapshot->balance(AccountCode::BKASH))->plus($snapshot->balance(AccountCode::NAGAD)),
            ))->icon(Heroicon::OutlinedCreditCard),
        ];
    }
}
