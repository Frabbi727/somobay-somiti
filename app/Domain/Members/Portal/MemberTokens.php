<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * App sign-in tokens. Each sign-in is a "family": a 60-minute access token (ability member) and a
 * 30-day refresh token (ability refresh). A refresh token works once; presenting a spent one again
 * later than REUSE_GRACE_SECONDS means it was copied, so every token of that user is revoked.
 */
final class MemberTokens
{
    public const int ACCESS_MINUTES = 60;

    public const int REFRESH_DAYS = 30;

    /** A spent refresh token presented again this soon is a retry (lost response, parallel refresh), not theft. */
    public const int REUSE_GRACE_SECONDS = 30;

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(User $user, ?string $family = null): array
    {
        $family ??= (string) Str::uuid();
        $now = CarbonImmutable::now();

        $access = $user->createToken('access:'.$family, ['member'], $now->addMinutes(self::ACCESS_MINUTES));
        $refresh = $user->createToken('refresh:'.$family, ['refresh'], $now->addDays(self::REFRESH_DAYS));

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_MINUTES * 60,
        ];
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     *
     * @throws AuthenticationException
     */
    public function refresh(string $plainRefreshToken): array
    {
        $result = DB::transaction(function () use ($plainRefreshToken): ?array {
            $found = PersonalAccessToken::findToken($plainRefreshToken);
            $token = $found === null ? null : PersonalAccessToken::query()->whereKey($found->getKey())->lockForUpdate()->first();
            $user = $token?->tokenable;

            if ($token === null || ! $user instanceof User) {
                return null;
            }

            if (str_starts_with($token->name, 'used:')) {
                $usedAt = $token->expires_at;

                if ($usedAt === null || $usedAt->lt(CarbonImmutable::now()->subSeconds(self::REUSE_GRACE_SECONDS))) {
                    $this->revokeAll($user);
                }

                return null;
            }

            if (! str_starts_with($token->name, 'refresh:') || ! $token->can('refresh') || ($token->expires_at !== null && $token->expires_at->isPast())) {
                return null;
            }

            $family = substr($token->name, strlen('refresh:'));
            $user->tokens()->where('name', 'access:'.$family)->delete();
            $token->forceFill(['name' => 'used:'.$family, 'expires_at' => CarbonImmutable::now()])->save();

            return $this->issue($user, $family);
        }, attempts: 3);

        // Outside the transaction, so revoking a replayed token is committed before we refuse.
        return $result ?? throw new AuthenticationException;
    }

    public function revokeFamily(PersonalAccessToken $accessToken): void
    {
        $family = substr($accessToken->name, strlen('access:'));
        $owner = $accessToken->tokenable;

        if ($owner instanceof User) {
            $owner->tokens()->whereIn('name', ['access:'.$family, 'refresh:'.$family, 'used:'.$family])->delete();
        }
    }

    /**
     * Ends the short-lived access tokens but keeps refresh tokens, so the app's next refresh
     * gets a token for the user's new account type (applicant → member) without signing in.
     */
    public function revokeAccessTokens(User $user): void
    {
        $user->tokens()->where('name', 'like', 'access:%')->delete();
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
