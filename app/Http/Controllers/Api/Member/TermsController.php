<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Terms\Actions\AcceptTerms;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The somiti's rules and declaration for the app: read them, and agree to the current version.
 */
final class TermsController
{
    use ResolvesMember;

    public function current(Request $request, AccountTypes $accounts, PortalAccounts $portal, TermsGate $gate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $terms = MembershipTerms::current();
        $type = $accounts->of($user);
        abort_if($type === null, 403);
        $member = $type === AccountType::Member ? $portal->activeMemberOf($user) : null;

        return ApiResponse::ok([
            'version' => $terms->version,
            'rules_bn' => $terms->rules_bn,
            'rules_en' => $terms->rules_en,
            'declaration_bn' => $terms->declaration_bn,
            'declaration_en' => $terms->declaration_en,
            'accepted' => $member === null ? null : ! $gate->needsAcceptance($member),
        ]);
    }

    public function accept(Request $request, AcceptTerms $accept): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $accept($user, self::member($request), new TermsConsent(
            (int) $data['version'],
            ConsentChannel::App,
            (string) $data['idempotency_key'],
            $request->ip(),
        ));

        return ApiResponse::ok(null, __('terms.api.accepted'));
    }
}
