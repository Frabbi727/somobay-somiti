<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Listeners;

use App\Domain\Integrity\Events\IntegrityCheckFailed;
use App\Domain\Integrity\Notifications\IntegrityCheckFailedMail;
use App\Domain\Notifications\Services\SmsSender;
use App\Enums\Role;
use App\Filament\Pages\Reports\IntegrityReport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Tells every super admin and accountant: in the panel, by email and by SMS (when they have them).
 */
final class AlertIntegrityFailure
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(IntegrityCheckFailed $event): void
    {
        $run = $event->run;

        $recipients = User::query()
            ->role([Role::SuperAdmin->value, Role::Accountant->value])
            ->get();

        foreach ($recipients as $user) {
            $title = __('integrity.alert.subject', [], $user->locale);
            $body = __('integrity.alert.body', ['count' => $run->findings_count], $user->locale);

            Notification::make()
                ->danger()
                ->title($title)
                ->body($body)
                ->actions([
                    Action::make('open')
                        ->label(__('integrity.alert.open', [], $user->locale))
                        ->url(IntegrityReport::getUrl(panel: 'admin')),
                ])
                ->sendToDatabase($user);

            if ($user->email !== null) {
                $user->notify(new IntegrityCheckFailedMail($run));
            }

            if ($user->mobile !== null) {
                $this->sms->queue($user->mobile, $title.' '.$body, dedupeKey: "integrity:{$run->id}:{$user->id}");
            }
        }
    }
}
