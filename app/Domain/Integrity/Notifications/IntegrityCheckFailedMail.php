<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Notifications;

use App\Domain\Integrity\Models\IntegrityRun;
use App\Filament\Pages\Reports\IntegrityReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class IntegrityCheckFailedMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IntegrityRun $run) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->error()
            ->subject(__('integrity.alert.subject', [], $notifiable->locale))
            ->line(__('integrity.alert.body', ['count' => $this->run->findings_count], $notifiable->locale));

        foreach ($this->run->findings()->orderBy('id')->limit(10)->pluck('message') as $finding) {
            $message->line('• '.$finding);
        }

        return $message->action(__('integrity.alert.open', [], $notifiable->locale), IntegrityReport::getUrl(panel: 'admin'));
    }
}
