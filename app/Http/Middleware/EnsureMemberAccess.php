<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Http\Api\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the member behind the token. An exited member, or an account that is not a member, is
 * refused and signed out everywhere. Controllers read the member from the request, never from input.
 */
final class EnsureMemberAccess
{
    public function __construct(
        private readonly PortalAccounts $accounts,
        private readonly MemberTokens $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $member = $user instanceof User ? $this->accounts->activeMemberOf($user) : null;

        if ($member === null) {
            if ($user instanceof User) {
                $this->tokens->revokeAll($user);
            }

            return ApiResponse::error(__('api.errors.forbidden'), 403);
        }

        $request->attributes->set('member', $member);

        return $next($request);
    }
}
