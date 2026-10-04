<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages\Auth;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\LoginCodes;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Closure;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Members sign in with their mobile number and a password — or, when SMS codes are switched on
 * (somiti.portal_otp), with a one-time code. Exited members cannot sign in.
 */
final class MemberLogin extends Login
{
    public function getTitle(): string
    {
        return __('portal.login.heading');
    }

    public function getHeading(): string
    {
        return __('portal.login.heading');
    }

    public function getSubheading(): Htmlable
    {
        return new HtmlString(e(__('login.member.subheading')).'<br><a href="'.e(route('filament.admin.auth.login')).'" class="fi-link font-semibold text-primary-600 hover:underline dark:text-primary-400">'.e(__('login.member.staff_link')).'</a>');
    }

    public static function codesEnabled(): bool
    {
        return (bool) config('somiti.portal_otp');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('mobile')
                ->label(__('portal.login.mobile'))
                ->tel()
                ->placeholder('01XXXXXXXXX')
                ->prefixIcon(Heroicon::OutlinedDevicePhoneMobile)
                ->helperText(__('login.member.mobile_help'))
                ->extraInputAttributes(['inputmode' => 'tel'])
                ->autocomplete('tel')
                ->required()
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (MobileNumber::normalize((string) $value) === null) {
                        $fail(__('portal.errors.mobile_format'));
                    }
                })
                ->autofocus(),
            Radio::make('method')
                ->label(__('portal.login.method'))
                ->options(['password' => __('portal.login.password'), 'code' => __('portal.login.code')])
                ->default('password')
                ->inline()
                ->live()
                ->visible(fn (): bool => self::codesEnabled()),
            TextInput::make('password')
                ->label(__('portal.login.password'))
                ->prefixIcon(Heroicon::OutlinedLockClosed)
                ->password()
                ->revealable()
                ->autocomplete('current-password')
                ->helperText(__('portal.login.password_help'))
                ->required(fn (Get $get): bool => ! $this->usesCode($get('method')))
                ->visible(fn (Get $get): bool => ! $this->usesCode($get('method'))),
            TextInput::make('code')
                ->label(__('portal.login.code'))
                ->inputMode('numeric')
                ->autocomplete('one-time-code')
                ->maxLength(6)
                ->required(fn (Get $get): bool => $this->usesCode($get('method')))
                ->visible(fn (Get $get): bool => $this->usesCode($get('method')))
                ->hintAction(
                    Action::make('sendCode')
                        ->label(__('portal.login.send_code'))
                        ->action(fn () => $this->sendCode()),
                ),
            $this->getRememberFormComponent()->default(true),
        ]);
    }

    public function sendCode(): void
    {
        if (! self::codesEnabled()) {
            return;
        }

        try {
            app(LoginCodes::class)->send((string) ($this->data['mobile'] ?? ''), (string) request()->ip());
            Notification::make()->title(__('portal.login.code_sent'))->success()->send();
        } catch (DomainRuleViolation $violation) {
            throw ValidationException::withMessages(['data.mobile' => $violation->getMessage()]);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $remember = (bool) ($data['remember'] ?? false);

        /** @var User $user */
        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($data): User {
            $user = $this->usesCode($data['method'] ?? null) ? $this->userFromCode($data) : $this->userFromPassword($data);

            if ($user === null || ! $user->canAccessPanel(Filament::getPanel('member'))) {
                $this->throwFailureValidationException();
            }

            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function userFromPassword(array $data): ?User
    {
        $member = $this->member((string) ($data['mobile'] ?? ''));

        if ($member === null) {
            return null;
        }

        $user = app(PortalAccounts::class)->forMember($member);

        return Hash::check((string) ($data['password'] ?? ''), $user->password) ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function userFromCode(array $data): ?User
    {
        if (! self::codesEnabled()) {
            return null;
        }

        try {
            $member = app(LoginCodes::class)->verify((string) ($data['mobile'] ?? ''), (string) ($data['code'] ?? ''));
        } catch (DomainRuleViolation $violation) {
            throw ValidationException::withMessages(['data.code' => $violation->getMessage()]);
        }

        return $member->status === MemberStatus::Exited ? null : app(PortalAccounts::class)->forMember($member);
    }

    private function member(string $mobile): ?Member
    {
        $normalized = MobileNumber::normalize($mobile);

        return $normalized === null ? null : Member::query()
            ->where('mobile', $normalized)
            ->where('status', '!=', MemberStatus::Exited)
            ->first();
    }

    private function usesCode(mixed $method): bool
    {
        return self::codesEnabled() && $method === 'code';
    }

    protected function throwFailureValidationException(): never
    {
        activity('auth')->event('sign_in_failed')->withProperties(['login' => $this->data['mobile'] ?? null, 'panel' => 'member'])->log('sign-in failed');

        throw ValidationException::withMessages([
            'data.mobile' => __('portal.errors.wrong_password'),
        ]);
    }
}
