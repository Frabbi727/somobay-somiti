<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use Illuminate\Support\Facades\Auth;

/**
 * Persists the language a staff member picks in the switcher, so it follows them across sessions.
 */
final class RememberUserLocale
{
    public function handle(LocaleChanged $event): void
    {
        $user = Auth::user();

        if ($user instanceof User && in_array($event->locale, ['bn', 'en'], true) && $user->locale !== $event->locale) {
            $user->forceFill(['locale' => $event->locale])->save();
        }
    }
}
