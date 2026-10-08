<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Portal\AccountType;
use App\Domain\Members\Portal\AccountTypes;
use App\Domain\Members\Portal\LoginCodes;
use App\Domain\Members\Portal\MemberCredentials;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Requests\Api\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * App sign-in with the same checks as the portal (MemberLogin), issuing token pairs instead of a session.
 */
final class AuthController
{
    use ResolvesMember;

    public function __construct(
        private readonly MemberCredentials $credentials,
        private readonly MemberTokens $tokens,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $mobile = (string) $request->string('mobile');
        $usesCode = $request->filled('code');

        if ($usesCode && ! config('somiti.portal_otp')) {
            throw ValidationException::withMessages(['code' => __('api.auth.codes_off')]);
        }

        /** @var User|null $user */
        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($request, $mobile, $usesCode): ?User {
            try {
                $user = $usesCode
                    ? $this->credentials->byCode($mobile, (string) $request->string('code'))
                    : $this->credentials->byPassword($mobile, (string) $request->string('password'));
            } catch (DomainRuleViolation $violation) {
                throw ValidationException::withMessages(['code' => $violation->getMessage()]);
            }

            if ($user !== null) {
                $timebox->returnEarly();
            }

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        if ($user === null && ! $usesCode && $this->credentials->isRejectedApplicant($mobile, (string) $request->string('password'))) {
            throw ValidationException::withMessages(['mobile' => __('portal.errors.registration_rejected')]);
        }

        if ($user === null) {
            throw ValidationException::withMessages(['mobile' => __('api.auth.failed')]);
        }

        return ApiResponse::ok($this->tokens->issue($user), __('api.auth.signed_in'));
    }

    public function sendCode(Request $request, LoginCodes $codes): JsonResponse
    {
        abort_unless((bool) config('somiti.portal_otp'), 404);
        $request->validate(['mobile' => ['required', 'string', 'max:20']]);

        $codes->send((string) $request->string('mobile'), (string) $request->ip());

        return ApiResponse::ok(null, __('portal.login.code_sent'));
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => ['required', 'string']]);

        return ApiResponse::ok($this->tokens->refresh((string) $request->string('refresh_token')));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revokeFamily($token);
        }

        return ApiResponse::ok(null, __('api.auth.signed_out'));
    }

    public function me(Request $request, AccountTypes $accounts, PortalAccounts $portal): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($accounts->of($user) === AccountType::Applicant) {
            $application = $accounts->openApplicationOf($user);
            abort_if($application === null, 403);

            return ApiResponse::ok([
                'account_type' => AccountType::Applicant->value,
                'mobile' => $application->mobile,
                'registration' => [
                    'status' => ApiValue::enum($application->status),
                    'next_action' => $application->nextAction()->value,
                ],
            ]);
        }

        $member = $portal->activeMemberOf($user);

        if ($member === null) {
            $this->tokens->revokeAll($user);

            return ApiResponse::error(__('api.errors.forbidden'), 403);
        }

        return ApiResponse::ok([
            'account_type' => AccountType::Member->value,
            'member_no' => $member->member_no,
            'name' => self::memberName($member),
            'status' => ApiValue::enum($member->status),
        ]);
    }
}
