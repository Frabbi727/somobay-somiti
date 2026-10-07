<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Filament\Member\Pages\Registration;
use App\Filament\Member\Pages\RegistrationStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone still registering lands on their registration status whatever portal page they open.
 */
final class RedirectApplicantsToRegistration
{
    public function __construct(private readonly AccountTypes $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->accounts->of($user) === AccountType::Applicant) {
            $allowed = [RegistrationStatus::getRouteName(), Registration::getRouteName(), 'filament.member.auth.logout'];

            if (! in_array($request->route()?->getName(), $allowed, true)) {
                return redirect(RegistrationStatus::getUrl());
            }
        }

        return $next($request);
    }
}
