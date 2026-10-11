<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Services\InAppNotifier;
use App\Domain\Reporting\ApprovalQueue;
use App\Filament\Pages\Dashboard;
use App\Filament\Support\Display;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily nudge: everyone with items that have waited more than somiti.approval_reminder_days for
 * them gets one bell message (at most one a day). 0 days turns reminders off. Returns how many
 * people were reminded.
 */
final class SendApprovalReminders
{
    public function __construct(
        private readonly ApprovalQueue $queue,
        private readonly InAppNotifier $bell,
    ) {}

    public function __invoke(CarbonImmutable $now): int
    {
        $days = (int) config('somiti.approval_reminder_days');

        if ($days <= 0) {
            return 0;
        }

        $cutoff = $now->subDays($days);
        $counts = [];

        foreach ($this->queue->all() as $item) {
            if (! $item->since->isBefore($cutoff)) {
                continue;
            }

            foreach ($this->queue->whoCanAct($item) as $user) {
                $counts[$user->id] = ($counts[$user->id] ?? 0) + 1;
            }
        }

        foreach ($counts as $userId => $count) {
            $this->bell->send(
                key: 'reminder.waiting',
                recipients: fn (): Collection => User::query()->whereKey($userId)->get(),
                params: fn (): array => ['count' => Display::digits($count), 'days' => Display::digits($days)],
                url: Dashboard::getUrl(panel: 'admin'),
                status: 'warning',
                dedupe: 'approval-reminder:'.$now->toDateString(),
            );
        }

        return count($counts);
    }
}
