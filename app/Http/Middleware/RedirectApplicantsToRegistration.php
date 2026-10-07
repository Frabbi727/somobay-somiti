<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Filament\Member\Pages\Dashboard;
use App\Filament\Member\Pages\Registration;
use App\Filament\Member\Pages\RegistrationStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone still registering lands on their registration status whatever portal page they open; a
 * member (e.g. just approved, reloading a registration page) is sent to the dashboard instead.
 */
final class RedirectApplicantsToRegistration
{
    public function __construct(private readonly AccountTypes $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $route = $request->route()?->getName();
        $registrationRoutes = [RegistrationStatus::getRouteName(), Registration::getRouteName()];

        $type = $this->accounts->of($user);

        if ($type === AccountType::Applicant && ! in_array($route, [...$registrationRoutes, 'filament.member.auth.logout'], true)) {
            return redirect(RegistrationStatus::getUrl());
        }

        if ($type === AccountType::Member && in_array($route, $registrationRoutes, true)) {
            return redirect(Dashboard::getUrl());
        }

        return $next($request);
    }
}
