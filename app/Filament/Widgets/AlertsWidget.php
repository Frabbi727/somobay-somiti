<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Integrity\Enums\IntegrityRunStatus;
use App\Domain\Integrity\Models\IntegrityRun;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Area;
use App\Filament\Clusters\Settings\Resources\SmsMessages\SmsMessageResource;
use App\Filament\Pages\Reports\IntegrityReport;
use App\Filament\Support\Display;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Problems someone responsible should look at: the books' nightly self-check (accounting) and SMS
 * that could not be delivered (messaging). Each tile only appears for the people who can fix it.
 */
final class AlertsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Failed SMS from the last this many days count. */
    public const int SMS_DAYS = 30;

    protected function getHeading(): string
    {
        return __('dashboard.alerts');
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && (Area::Accounting->allows($user) || Area::Messaging->allows($user));
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_filter([
            Area::Accounting->allows($user) ? $this->integrity() : null,
            Area::Messaging->allows($user) ? $this->failedSms() : null,
        ]));
    }

    private function integrity(): Stat
    {
        $run = IntegrityRun::query()->latest('id')->first();

        $stat = match (true) {
            $run === null => Stat::make(__('dashboard.integrity'), __('dashboard.integrity_never'))->color('gray'),
            $run->status === IntegrityRunStatus::Failed => Stat::make(__('dashboard.integrity'), Display::digits($run->findings_count))
                ->description(__('dashboard.integrity_problems'))->color('danger'),
            default => Stat::make(__('dashboard.integrity'), $run->status->getLabel())
                ->description(__('dashboard.integrity_checked', ['at' => Display::dateTime($run->finished_at ?? $run->started_at)]))
                ->color($run->status === IntegrityRunStatus::Passed ? 'success' : 'gray'),
        };

        return $stat->icon(Heroicon::OutlinedShieldCheck)->url(IntegrityReport::getUrl());
    }

    private function failedSms(): Stat
    {
        $count = SmsMessage::query()
            ->where('status', SmsStatus::Failed)
            ->where('created_at', '>=', now()->subDays(self::SMS_DAYS))
            ->count();

        return Stat::make(__('dashboard.failed_sms'), Display::digits($count))
            ->description(__($count > 0 ? 'dashboard.failed_sms_help' : 'dashboard.failed_sms_none', ['days' => Display::digits(self::SMS_DAYS)]))
            ->color($count > 0 ? 'warning' : 'gray')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->url(SmsMessageResource::getUrl());
    }
}
