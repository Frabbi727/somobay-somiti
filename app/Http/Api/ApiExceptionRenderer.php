<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every exception on /api/* into the app envelope with a localised, non-technical message.
 */
final class ApiExceptionRenderer
{
    /** Domain rule keys that mean "conflict" rather than "invalid". */
    private const array CONFLICTS = ['payments.errors.idempotency_conflict', 'registration.errors.idempotency_conflict'];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(__('api.errors.validation'), 422, $e->errors()),
            $e instanceof DomainRuleViolation => ApiResponse::error($e->getMessage(), in_array($e->translationKey, self::CONFLICTS, true) ? 409 : 422),
            // Laravel wraps Sanctum's MissingAbilityException in an AccessDeniedHttpException: a refresh
            // token used as an access token is "sign in again", not "forbidden".
            $e instanceof AuthenticationException, $e instanceof MissingAbilityException, $e->getPrevious() instanceof MissingAbilityException => ApiResponse::error(__('api.errors.unauthenticated'), 401),
            $e instanceof AuthorizationException => ApiResponse::error(__('api.errors.forbidden'), 403),
            $e instanceof ModelNotFoundException => ApiResponse::error(__('api.errors.not_found'), 404),
            $e instanceof ThrottleRequestsException => ApiResponse::error(__('api.errors.throttled'), 429),
            $e instanceof HttpExceptionInterface => ApiResponse::error(match ($e->getStatusCode()) {
                401 => __('api.errors.unauthenticated'),
                403 => __('api.errors.forbidden'),
                404 => __('api.errors.not_found'),
                405 => __('api.errors.method'),
                429 => __('api.errors.throttled'),
                default => __('api.errors.server'),
            }, $e->getStatusCode()),
            default => ApiResponse::error(__('api.errors.server'), 500),
        };
    }
}
