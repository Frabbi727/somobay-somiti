<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Errors\ErrorReference;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;

/**
 * Runs a domain Action from the UI and explains any refusal in plain words (§7.3), never a stack
 * trace. Whatever went wrong inside the Action, its transaction has rolled back, so each message
 * can honestly say that nothing was saved:
 *  - a business rule → its own translated message;
 *  - no permission (e.g. it was taken away, or someone else acted first) → what to do;
 *  - someone else changing the same rows at that moment → refresh and check;
 *  - a database safety rule → nothing saved, with a reference for support (logged).
 * Anything else is not caught here: the panel shows its general error and the log has the details.
 */
final class DomainActionRunner
{
    /** PostgreSQL codes for "another transaction got in the way": serialization, deadlock, lock timeout, unique key. */
    private const array CONCURRENCY = ['40001', '40P01', '55P03', '23505'];

    /** PostgreSQL codes for a database rule refusing the change: check, foreign key, raised by a trigger. */
    private const array RULES = ['23514', '23503', 'P0001'];

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
            self::refuse($violation->getMessage());
        } catch (AuthorizationException) {
            self::refuse(__('errors.no_permission'));
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (! in_array($state, [...self::CONCURRENCY, ...self::RULES], true)) {
                throw $exception;
            }

            report($exception);

            self::refuse(__(in_array($state, self::CONCURRENCY, true) ? 'errors.concurrent_change' : 'errors.rule_blocked', [
                'reference' => ErrorReference::current(),
            ]));
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

    /**
     * @throws Halt
     */
    private static function refuse(string $message): never
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }
}
