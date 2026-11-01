<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Filament\Member\Pages\AcceptRules;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A member who has not accepted the current rules can only read and accept them (or sign out).
 * People still registering accept at submit, so they are not stopped here.
 */
final class RequireCurrentTermsInPortal
{
    public function __construct(
        private readonly AccountTypes $accounts,
        private readonly PortalAccounts $portal,
        private readonly TermsGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->accounts->of($user) !== AccountType::Member) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if (in_array($route, [AcceptRules::getRouteName(), 'filament.member.auth.logout'], true)) {
            return $next($request);
        }

        $member = $this->portal->activeMemberOf($user);

        if ($member !== null && $this->gate->needsAcceptance($member)) {
            return redirect(AcceptRules::getUrl());
        }

        return $next($request);
    }
}
