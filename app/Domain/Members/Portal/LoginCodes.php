<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsFormat;
use App\Domain\Notifications\Services\SmsSender;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Contact\MobileNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time portal login codes sent by SMS: 6 digits, valid for 5 minutes, 5 tries, at most
 * 3 codes per mobile per 10 minutes. Only the hash is kept. Unknown numbers get the same answer
 * as known ones, so the form cannot be used to discover who is a member.
 */
final class LoginCodes
{
    public const int MINUTES = 5;

    public const int MAX_TRIES = 5;

    public function __construct(private readonly SmsSender $sms) {}

    public function send(string $mobileInput, string $ip): void
    {
        $mobile = MobileNumber::normalize($mobileInput) ?? throw DomainRuleViolation::because('portal.errors.mobile_format');

        foreach (['portal-code:'.$mobile => 3, 'portal-code-ip:'.$ip => 10] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw DomainRuleViolation::because('portal.errors.too_many_codes', ['seconds' => RateLimiter::availableIn($key)]);
            }

            RateLimiter::hit($key, 600);
        }

        $member = $this->member($mobile);

        if ($member === null) {
            return;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->cacheKey($mobile), ['hash' => Hash::make($code), 'tries' => 0], now()->addMinutes(self::MINUTES));

        $this->sms->template(SmsTemplateKey::LoginCode, $mobile, [
            'code' => SmsFormat::digits($code),
            'minutes' => SmsFormat::digits(self::MINUTES),
        ], $member);

        if (app()->isLocal()) {
            Log::info('Portal login code for '.$mobile.': '.$code);
        }
    }

    public function verify(string $mobileInput, string $code): Member
    {
        $mobile = MobileNumber::normalize($mobileInput) ?? throw DomainRuleViolation::because('portal.errors.mobile_format');
        $state = Cache::get($this->cacheKey($mobile));

        if (! is_array($state) || ($state['tries'] ?? 0) >= self::MAX_TRIES) {
            Cache::forget($this->cacheKey($mobile));

            throw DomainRuleViolation::because('portal.errors.code_expired');
        }

        if (! Hash::check(trim($code), (string) $state['hash'])) {
            Cache::put($this->cacheKey($mobile), ['hash' => $state['hash'], 'tries' => $state['tries'] + 1], now()->addMinutes(self::MINUTES));

            throw DomainRuleViolation::because('portal.errors.code_wrong');
        }

        Cache::forget($this->cacheKey($mobile));

        return $this->member($mobile) ?? throw DomainRuleViolation::because('portal.errors.code_expired');
    }

    private function member(string $mobile): ?Member
    {
        return Member::query()->where('mobile', $mobile)->where('status', '!=', MemberStatus::Exited)->first();
    }

    private function cacheKey(string $mobile): string
    {
        return 'portal-login-code:'.$mobile;
    }
}
