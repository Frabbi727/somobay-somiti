<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\AccountTypes;
use App\Http\Api\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the open registration behind an applicant token. Controllers read it from the request,
 * never from input.
 */
final class EnsureApplicantAccess
{
    public function __construct(private readonly AccountTypes $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $application = $user instanceof User ? $this->accounts->openApplicationOf($user) : null;

        if ($application === null) {
            return ApiResponse::error(__('api.errors.forbidden'), 403);
        }

        $request->attributes->set('application', $application);

        return $next($request);
    }
}
