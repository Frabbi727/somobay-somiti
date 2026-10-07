<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Services;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Tells everyone holding the role at the current step that a registration waits for them
 * (admin panel bell). Sent after commit, so a rolled-back decision notifies nobody.
 */
final class ApproverNotifier
{
    public function notifyStep(MemberApplication $application): void
    {
        $role = $application->currentRole();

        if ($role === null) {
            return;
        }

        $name = $application->name_bn ?? $application->mobile;

        DB::afterCommit(function () use ($role, $name): void {
            $approvers = User::role($role->value)->whereNull('deactivated_at')->get();

            if ($approvers->isEmpty()) {
                return;
            }

            Notification::make()
                ->title(__('registration.notifications.waiting_for_you', ['name' => $name]))
                ->icon(Heroicon::OutlinedUserPlus)
                ->info()
                ->sendToDatabase($approvers);
        });
    }
}
