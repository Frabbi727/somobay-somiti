<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\LoginCodes;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mobile + SMS code (default) or mobile + password, both rate limited.
 */
#[Layout('layouts.portal-guest')]
#[Title('Portal')]
final class Login extends Component
{
    public string $mobile = '';

    public string $code = '';

    public string $password = '';

    public bool $codeSent = false;

    public bool $usePassword = false;

    public ?string $error = null;

    public function boot(): void
    {
        $locale = session('portal_locale', 'bn');
        app()->setLocale(in_array($locale, ['bn', 'en'], true) ? $locale : 'bn');
    }

    public function sendCode(LoginCodes $codes): void
    {
        $this->error = null;

        try {
            $codes->send($this->mobile, (string) request()->ip());
            $this->codeSent = true;
        } catch (DomainRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }
    }

    public function verifyCode(LoginCodes $codes, PortalAccounts $accounts): mixed
    {
        $this->error = null;

        try {
            $member = $codes->verify($this->mobile, $this->code);
        } catch (DomainRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return null;
        }

        return $this->signIn($accounts->forMember($member));
    }

    public function loginWithPassword(PortalAccounts $accounts): mixed
    {
        $this->error = null;
        $key = 'portal-password:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = __('portal.errors.too_many_codes', ['seconds' => RateLimiter::availableIn($key)]);

            return null;
        }

        $mobile = MobileNumber::normalize($this->mobile);
        $member = $mobile === null ? null : Member::query()->where('mobile', $mobile)->first();
        $user = $member === null ? null : $accounts->forMember($member);

        if ($user === null || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 600);
            $this->error = __('portal.errors.wrong_password');

            return null;
        }

        RateLimiter::clear($key);

        return $this->signIn($user);
    }

    public function render(): View
    {
        return view('livewire.portal.login');
    }

    private function signIn(User $user): mixed
    {
        Auth::login($user);
        session()->regenerate();

        return $this->redirectRoute('portal.dashboard', navigate: true);
    }
}
