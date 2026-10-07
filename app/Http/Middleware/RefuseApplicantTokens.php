<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Member screens answer a registering person's token with 403 and a hint to update the app —
 * not with 401, which would make an old app build refresh and sign out in a loop.
 */
final class RefuseApplicantTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->tokenCan('applicant') && ! $user->tokenCan('member')) {
            return ApiResponse::error(__('api.registration.member_only'), 403);
        }

        return $next($request);
    }
}
