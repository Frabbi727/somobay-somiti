<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Runs a domain Action from the UI: business-rule violations become a translated danger
 * notification and halt the Filament action, so users never see a stack trace (§7.3).
 */
final class DomainActionRunner
{
    /**
     * @template TResult
     *
     * @param  Closure(User): TResult  $callback
     * @return TResult
     *
     * @throws Halt
     */
    public static function run(Closure $callback): mixed
    {
        try {
            return $callback(self::actor());
        } catch (DomainRuleViolation $violation) {
            Notification::make()
                ->title($violation->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    public static function actor(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
