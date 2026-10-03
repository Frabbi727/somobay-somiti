<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Jobs;

use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Filament\Support\Display;
use App\Models\User;
use App\Support\Time\YearMonth;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a month's generation on the queue and reports back through a database notification.
 */
final class GenerateMonthlyDuesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $month,
        public readonly ?int $requestedBy = null,
    ) {}

    public function handle(GenerateMonthlyDues $generate): void
    {
        $user = $this->requestedBy === null ? null : User::query()->find($this->requestedBy);
        $previousLocale = app()->getLocale();

        // Report in the requester's language, then restore it (jobs may run in-process).
        if ($user !== null) {
            app()->setLocale($user->locale);
        }

        try {
            $result = $generate(YearMonth::parse($this->month), $user);

            $notification = Notification::make()
                ->success()
                ->title(__('dues.generate.done', [
                    'month' => Display::yearMonth($result->month),
                    'count' => Display::digits($result->newCount()),
                    'amount' => Display::money($result->newTotal()),
                ]));
        } catch (DomainRuleViolation $violation) {
            $notification = Notification::make()->danger()->title($violation->getMessage());
        }

        if ($user !== null) {
            $notification->sendToDatabase($user);
        }

        app()->setLocale($previousLocale);
    }
}
