<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\PortalAccounts;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only signed-in members (role "member" with a linked member record) into /portal,
 * and applies their chosen language.
 */
final class EnsurePortalMember
{
    public function __construct(private readonly PortalAccounts $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->isStaff() || $this->accounts->memberOf($user) === null) {
            return redirect()->route('portal.login');
        }

        app()->setLocale($user->locale);

        return $next($request);
    }
}
