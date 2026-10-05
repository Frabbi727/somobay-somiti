<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The committee's sign-in: email + password (and the app code when two-factor is on), with a clear
 * heading, a hint where members should go instead, and the language switch.
 */
final class StaffLogin extends Login
{
    public function getTitle(): string
    {
        return __('login.staff.title');
    }

    public function getHeading(): string
    {
        return __('login.staff.heading');
    }

    public function getSubheading(): Htmlable
    {
        return new HtmlString(e(__('login.staff.subheading')).'<br><a href="'.e(route('filament.member.auth.login')).'" class="fi-link font-semibold text-primary-600 hover:underline dark:text-primary-400">'.e(__('login.staff.member_link')).'</a>'.'<br><a href="'.e(route('privacy')).'" class="fi-link text-sm text-gray-500 hover:underline dark:text-gray-400">'.e(__('privacy.link')).'</a>');
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('login.staff.email'))
            ->placeholder('name@example.com')
            ->prefixIcon(Heroicon::OutlinedEnvelope)
            ->email()
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('login.password'))
            ->prefixIcon(Heroicon::OutlinedLockClosed)
            ->helperText(__('login.staff.forgot'))
            ->password()
            ->revealable()
            ->autocomplete('current-password')
            ->required();
    }
}
