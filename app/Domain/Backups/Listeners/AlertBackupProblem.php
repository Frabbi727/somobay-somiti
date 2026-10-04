<?php

declare(strict_types=1);

namespace App\Domain\Backups\Listeners;

use App\Enums\Role;
use App\Filament\Clusters\Settings\Pages\BackupsPage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * A failed or missing night's backup shows up in every super admin's panel (the email goes out too).
 */
final class AlertBackupProblem
{
    public function handle(BackupHasFailed|UnhealthyBackupWasFound $event): void
    {
        $disk = $event->diskName ?? '—';

        foreach (User::query()->role(Role::SuperAdmin->value)->whereNull('deactivated_at')->get() as $user) {
            Notification::make()
                ->danger()
                ->title(__($event instanceof BackupHasFailed ? 'backups.notify.failed' : 'backups.notify.unhealthy', ['disk' => $disk], $user->locale))
                ->actions([
                    Action::make('open')
                        ->label(__('backups.title', [], $user->locale))
                        ->url(BackupsPage::getUrl(panel: 'admin')),
                ])
                ->sendToDatabase($user);
        }
    }
}
