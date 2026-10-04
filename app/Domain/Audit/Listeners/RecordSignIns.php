<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Writes every sign-in, sign-out and failed sign-in to the audit log (log "auth"). A failed attempt
 * records only the email or mobile that was typed, never the password.
 */
final class RecordSignIns
{
    public function handle(Login|Logout|Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        $entry = activity('auth')->event(match (true) {
            $event instanceof Login => 'signed_in',
            $event instanceof Logout => 'signed_out',
            default => 'sign_in_failed',
        });

        if ($user !== null) {
            $entry->causedBy($user)->performedOn($user);
        }

        if ($event instanceof Failed) {
            $entry->withProperties(['login' => $event->credentials['email'] ?? $event->credentials['mobile'] ?? null]);
        }

        $entry->log(match (true) {
            $event instanceof Login => 'signed in',
            $event instanceof Logout => 'signed out',
            default => 'sign-in failed',
        });
    }
}
