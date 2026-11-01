<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Http\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `member`: until the member accepts the current rules the app gets a 403 it can
 * recognise by `meta.code` and route to its rules screen.
 */
final class RequireCurrentTermsInApi
{
    public function __construct(private readonly TermsGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->attributes->get('member');

        if ($member instanceof Member && $this->gate->needsAcceptance($member)) {
            return ApiResponse::error(
                __('terms.errors.acceptance_required'),
                403,
                null,
                ['code' => 'terms_acceptance_required', 'version' => (string) MembershipTerms::current()->version],
            );
        }

        return $next($request);
    }
}
