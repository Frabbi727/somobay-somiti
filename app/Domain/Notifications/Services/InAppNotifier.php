<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * The bell 🔔 in both panels. Every message says what happened and what to do next
 * (lang/{bn,en}/notifications.php: "{key}.title" and "{key}.body").
 *
 * Messages go out after the surrounding transaction commits, so an action that rolls back tells
 * nobody anything. Recipients are worked out at that moment and each message is written in the
 * recipient's own language. Deactivated users are skipped.
 */
final class InAppNotifier
{
    /**
     * @param  Closure(): iterable<User>  $recipients
     * @param  Closure(): array<string, string>  $params  built in each recipient's language
     * @param  string|null  $dedupe  at most one message with this key per user per day
     */
    public function send(
        string $key,
        Closure $recipients,
        Closure $params,
        ?string $url = null,
        string $status = 'info',
        ?string $dedupe = null,
    ): void {
        DB::afterCommit(function () use ($key, $recipients, $params, $url, $status, $dedupe): void {
            $seen = [];

            foreach ($recipients() as $user) {
                if (isset($seen[$user->id]) || $user->deactivated_at !== null) {
                    continue;
                }

                $seen[$user->id] = true;

                if ($dedupe !== null && $this->sentToday($user, $dedupe)) {
                    continue;
                }

                $this->inLocale($user->locale, function () use ($user, $key, $params, $url, $status, $dedupe): void {
                    $values = $params();

                    Notification::make()
                        ->title(__("notifications.{$key}.title", $values))
                        ->body(__("notifications.{$key}.body", $values))
                        ->status($status)
                        ->viewData(['dedupe' => $dedupe])
                        ->actions($url === null ? [] : [
                            Action::make('open')->label(__('notifications.open'))->url($url)->markAsRead(),
                        ])
                        ->sendToDatabase($user);
                });
            }
        });
    }

    private function sentToday(User $user, string $dedupe): bool
    {
        return $user->notifications()
            ->where('data->viewData->dedupe', $dedupe)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }

    private function inLocale(string $locale, Closure $callback): void
    {
        $previous = App::getLocale();
        App::setLocale(in_array($locale, ['bn', 'en'], true) ? $locale : $previous);

        try {
            $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
